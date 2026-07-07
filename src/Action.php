<?php

namespace Iak\Action;

use DateInterval;
use DateTimeInterface;
use Iak\Action\Execution\Idempotency;
use Iak\Action\Execution\MemoizedResults;
use Iak\Action\Testing\Testable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;

/**
 * @method mixed handle(mixed ...$args) Execute the action with the given arguments. This method must be implemented by concrete action classes.
 */
abstract class Action
{
    use HandlesEvents;

    /**
     * When true, the mock-binding test helpers are allowed to run outside
     * test/local environments. Toggled explicitly via allowTestHelpers().
     */
    protected static bool $allowTestHelpers = false;

    /**
     * Opt the mock-binding test helpers (fake(), only(), without(), except())
     * into running outside test/local environments.
     *
     * The flag is process-wide; pass false to restore the guarded default.
     */
    public static function allowTestHelpers(bool $allow = true): void
    {
        static::$allowTestHelpers = $allow;
    }

    /**
     * Fail loudly when a mock-binding helper is used outside a test context.
     *
     * These helpers replace real actions with Mockery mocks in the container;
     * a forgotten call in staging/production (worst of all under Octane, where
     * the swap outlives the request) would silently return fake data. The
     * guard turns that fail-silent-wrong into a fail-loud with instructions.
     *
     * @internal Called by Action::fake() and the Testable mock helpers.
     */
    public static function guardTestHelpers(string $helper): void
    {
        if (static::$allowTestHelpers) {
            return;
        }

        // Resolve the app from the container (matching the container-access
        // style used elsewhere) rather than a facade.
        $app = app();

        if ($app->runningUnitTests() || $app->environment('local', 'testing')) {
            return;
        }

        throw new \RuntimeException(
            "{$helper} is a testing-only helper: it binds Mockery mocks into the container "
            ."and cannot run in the [{$app->environment()}] environment, where it would silently "
            .'replace real actions with mocks. Call it only from your test suite, or opt in '
            .'explicitly with Action::allowTestHelpers().'
        );
    }

    /**
     * Record memory usage at a specific point in the action
     */
    public function recordMemory(string $name): void
    {
        // Dispatch an instance-scoped Laravel event so any profiler
        // attached to this specific instance can record the memory point.
        $eventName = 'action.record_memory.'.spl_object_hash($this);
        Event::dispatch($eventName, [$name]);
    }

    /**
     * Create a new instance of the action
     */
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Create a mock instance of the action for testing
     *
     * @return MockInterface&static
     */
    public static function fake(?string $alias = null): MockInterface|LegacyMockInterface
    {
        static::guardTestHelpers('Action::fake()');

        // https://stackoverflow.com/questions/76101686/mocking-static-method-in-same-class-mockery-laravel9
        $mock = Mockery::mock(static::class);

        if (! $mock instanceof static) {
            throw new \RuntimeException('Failed to create a mock of '.static::class);
        }

        app()->offsetSet($alias ?? static::class, $mock);

        return $mock;
    }

    /**
     * Wrap the action so handle() runs at most once per idempotency key,
     * returning the cached result of the first successful run afterwards.
     *
     * Only successful runs consume the key; if handle() throws, the exception
     * propagates and the next call executes again. Keys are scoped per action
     * class. Pass a TTL (seconds, DateInterval or expiry) to expire the entry,
     * or null to remember it forever, and a cache store name to override the
     * default store.
     *
     * @return PendingAction<static>
     */
    public function idempotent(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): PendingAction
    {
        return (new PendingAction($this))->idempotent($key, $ttl, $store);
    }

    /**
     * Answer with the closure's value when handle() ultimately throws. The
     * closure receives the Throwable and may rethrow to decline. See
     * PendingAction::fallback() for how it composes with the other wrappers.
     *
     * @param  \Closure(\Throwable): mixed  $fallback
     * @return PendingAction<static>
     */
    public function fallback(\Closure $fallback): PendingAction
    {
        return (new PendingAction($this))->fallback($fallback);
    }

    /**
     * Re-run handle() when it throws, up to $times total attempts, sleeping
     * the given backoff (milliseconds) between attempts. See
     * PendingAction::retry() for the backoff shapes and the NonRetryable
     * default of the $when filter.
     *
     * @param  (\Closure(int, \Throwable): int)|int|array<int, int>  $backoff
     * @param  (\Closure(\Throwable): bool)|null  $when
     * @return PendingAction<static>
     */
    public function retry(int $times = 3, \Closure|int|array $backoff = 0, ?\Closure $when = null): PendingAction
    {
        return (new PendingAction($this))->retry($times, $backoff, $when);
    }

    /**
     * Fail fast while a dependency is broken: after $threshold consecutive
     * failures handle() throws CircuitOpenException without executing until
     * the cooldown has passed. See PendingAction::circuitBreaker() for the
     * key scoping and how it composes with retry().
     *
     * @return PendingAction<static>
     */
    public function circuitBreaker(?string $key = null, int $threshold = 5, int $cooldown = 60, ?string $store = null): PendingAction
    {
        return (new PendingAction($this))->circuitBreaker($key, $threshold, $cooldown, $store);
    }

    /**
     * Remember the first successful result per key for the rest of the
     * process and return it without executing on later calls. See
     * PendingAction::memoize() for the key derivation and run() caveat.
     *
     * @return PendingAction<static>
     */
    public function memoize(?string $key = null): PendingAction
    {
        return (new PendingAction($this))->memoize($key);
    }

    /**
     * Forget every memoized action result in this process.
     */
    public static function flushMemoized(): void
    {
        app(MemoizedResults::class)->flush();
    }

    /**
     * Opt this call into the ActionStarted / ActionCompleted / ActionFailed
     * lifecycle events without any other wrapper feature. (Any wrapper
     * feature dispatches them already; plain handle() calls cannot.)
     *
     * @return PendingAction<static>
     */
    public function observed(): PendingAction
    {
        return (new PendingAction($this))->observed();
    }

    /**
     * Run the action after the response has been sent, via Laravel's
     * defer(). The closure receives the action with full typing, like
     * PendingAction::run().
     *
     * @param  \Closure(static): mixed  $callback
     */
    public function defer(\Closure $callback): void
    {
        (new PendingAction($this))->defer($callback);
    }

    /**
     * Never run two overlapping executions per key: mutex semantics with an
     * optional wait, throwing LockTimeoutException when the lock stays held.
     * See PendingAction::withoutOverlapping() for the details.
     *
     * @return PendingAction<static>
     */
    public function withoutOverlapping(?string $key = null, int $wait = 0, int $staleAfter = 60, ?string $store = null): PendingAction
    {
        return (new PendingAction($this))->withoutOverlapping($key, $wait, $staleAfter, $store);
    }

    /**
     * Rate-limit executions to $allow per $every seconds per key, throwing
     * ThrottledException once the budget is exhausted. See
     * PendingAction::throttle() for how it composes with retry().
     *
     * @return PendingAction<static>
     */
    public function throttle(?string $key = null, int $allow = 60, int $every = 60): PendingAction
    {
        return (new PendingAction($this))->throttle($key, $allow, $every);
    }

    /**
     * Run handle() inside a database transaction, retried up to $attempts
     * times on a concurrency error. See PendingAction::transactional().
     *
     * @return PendingAction<static>
     */
    public function transactional(int $attempts = 1, ?string $connection = null): PendingAction
    {
        return (new PendingAction($this))->transactional($attempts, $connection);
    }

    /**
     * Forget the cached idempotency result for the given key, so the next
     * idempotent() run for that key executes again. Applies the same
     * class-scoped key idempotent() uses.
     */
    public function forgetIdempotency(string $key, ?string $store = null): void
    {
        Cache::store($store)->forget(Idempotency::keyFor(static::class, $key));
    }

    /**
     * Create a testable instance of the action
     *
     * @param  (callable(Testable<static>): void)|null  $callback
     * @return Testable<static>
     */
    public static function test(?callable $callback = null): Testable
    {
        $action = static::make();
        $testable = new Testable($action);

        if (isset($callback)) {
            $callback($testable);
        }

        return $testable;
    }
}
