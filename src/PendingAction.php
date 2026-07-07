<?php

namespace Iak\Action;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Events\ActionCompleted;
use Iak\Action\Events\ActionFailed;
use Iak\Action\Events\ActionStarted;
use Iak\Action\Execution\CircuitBreaker;
use Iak\Action\Execution\Fallback;
use Iak\Action\Execution\Idempotency;
use Iak\Action\Execution\Memoize;
use Iak\Action\Execution\Middleware;
use Iak\Action\Execution\Retry;
use Iak\Action\Execution\Throttle;
use Iak\Action\Execution\Transactional;
use Iak\Action\Execution\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Traits\Conditionable;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Wraps an action with cross-cutting execution semantics — idempotency and
 * friends — composed as a middleware chain. Every configuring method returns
 * the same wrapper, so the features chain freely.
 *
 * This is a production helper (it never binds anything into the container and
 * is not gated by the test-helper guard). It owns the invocation the same way
 * Testable does, because the base Action cannot intercept a user's handle().
 *
 * handle() is intentionally not declared as a real method: the class-level
 * `@mixin TAction` projects the wrapped action's own handle() signature onto
 * this wrapper (typed arguments and return in PHPStan), and __call intercepts
 * the invocation. Editors that do not resolve generic mixins yet can use
 * run() for the same typing through a closure.
 *
 * @template-covariant TAction of Action
 *
 * @mixin TAction
 */
class PendingAction
{
    use Conditionable;

    /**
     * The fixed nesting order of the execution middleware, outermost first.
     * The order the features were chained in never matters — only this array
     * does — so the composition semantics stay predictable and documentable.
     */
    protected const ORDER = [
        'fallback',
        'memoize',
        'idempotent',
        'withoutOverlapping',
        'retry',
        'circuitBreaker',
        'throttle',
        'transactional',
    ];

    /**
     * @var TAction
     */
    protected readonly Action $action;

    /**
     * The configured middleware, keyed by their ORDER slot.
     *
     * @var array<string, Middleware>
     */
    protected array $middleware = [];

    /**
     * @param  TAction  $action
     */
    public function __construct(Action $action)
    {
        $this->action = $action;
    }

    /**
     * Run handle() at most once per idempotency key, returning the cached
     * result of the first successful run afterwards. Only successful runs
     * consume the key; if handle() throws, the exception propagates and the
     * next call executes again. Keys are scoped per action class.
     *
     * @return $this
     */
    public function idempotent(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): static
    {
        $this->middleware['idempotent'] = new Idempotency($this->action::class, $key, $ttl, $store);

        return $this;
    }

    /**
     * Answer with the closure's value when handle() ultimately throws —
     * whatever went wrong: the action itself, exhausted retries, an open
     * circuit breaker. The closure receives the Throwable and may rethrow to
     * decline. The fallback value is never cached as an idempotent result.
     *
     * @param  Closure(Throwable): mixed  $fallback
     * @return $this
     */
    public function fallback(Closure $fallback): static
    {
        $this->middleware['fallback'] = new Fallback($fallback);

        return $this;
    }

    /**
     * Re-run handle() when it throws, up to $times total attempts, sleeping
     * the given backoff (milliseconds; a fixed value, a per-attempt schedule
     * whose last entry repeats, or a closure receiving the attempt number and
     * the exception) between attempts. $when decides which exceptions are
     * retried; by default everything is except NonRetryable ones.
     *
     * Retrying nests inside idempotent(): failed attempts never consume the
     * key, and the first successful attempt is the result that gets cached.
     *
     * @param  (Closure(int, Throwable): int)|int|array<int, int>  $backoff
     * @param  (Closure(Throwable): bool)|null  $when
     * @param  bool  $jitter  Sleep a random duration between zero and the scheduled backoff instead of the exact value, so many processes retrying together spread out instead of arriving in synchronized waves.
     * @return $this
     */
    public function retry(int $times = 3, Closure|int|array $backoff = 0, ?Closure $when = null, bool $jitter = false): static
    {
        $this->middleware['retry'] = new Retry($times, $backoff, $when, $jitter);

        return $this;
    }

    /**
     * Remember the first successful result per key for the rest of the
     * process (container-scoped, so Octane and the test runner flush it for
     * free) and return it without executing on later calls. The key derives
     * from the handle() arguments — pass one explicitly for unserializable
     * arguments or when executing through run(). Keys are scoped per action
     * class. Flush with Action::flushMemoized().
     *
     * @return $this
     */
    public function memoize(?string $key = null): static
    {
        $this->middleware['memoize'] = new Memoize($key);

        return $this;
    }

    /**
     * Opt a plain call into the lifecycle events without any other wrapper
     * feature. Every wrapper-mediated invocation already dispatches
     * ActionStarted / ActionCompleted / ActionFailed; this exists for calls
     * that want only the events.
     *
     * @return $this
     */
    public function observed(): static
    {
        return $this;
    }

    /**
     * Run the action after the response has been sent, via Laravel's
     * defer(). The whole configured wrapper chain runs at that point, not
     * now. The closure receives the wrapped action with full typing — the
     * same shape as run() — because a deferred call has no immediate result
     * to return.
     *
     * @param  Closure(TAction): mixed  $callback
     */
    public function defer(Closure $callback): void
    {
        defer(fn (): mixed => $this->run($callback));
    }

    /**
     * Never run two overlapping executions per key: a held lock means this
     * call waits up to $wait seconds (zero fails immediately) and then throws
     * a LockTimeoutException. Unlike idempotent(), nothing is cached — every
     * call runs eventually, just never two at once. $staleAfter caps how long
     * a crashed holder can keep the lock. The key defaults to the action
     * class. Requires a lock-capable cache store and fails loudly otherwise.
     *
     * @return $this
     */
    public function withoutOverlapping(?string $key = null, int $wait = 0, int $staleAfter = 60, ?string $store = null): static
    {
        $this->middleware['withoutOverlapping'] = new WithoutOverlapping(
            $key ?? $this->action::class, $wait, $staleAfter, $store
        );

        return $this;
    }

    /**
     * Rate-limit executions to $allow per $every seconds per key (defaulting
     * to the action class). An exhausted budget throws ThrottledException —
     * carrying availableIn() — instead of blocking; compose retry() with a
     * backoff to wait a window out. Nested inside retry(), every attempt
     * consumes budget: the throttle protects the dependency, not the caller.
     *
     * @return $this
     */
    public function throttle(?string $key = null, int $allow = 60, int $every = 60): static
    {
        $this->middleware['throttle'] = new Throttle($key ?? $this->action::class, $allow, $every);

        return $this;
    }

    /**
     * Run handle() inside a database transaction, retried up to $attempts
     * times on a concurrency error (deadlock, serialization failure).
     * Innermost in the nesting order: a retry() around it gives every
     * attempt its own fresh transaction, and idempotent() outside it only
     * consumes the key for work that actually committed.
     *
     * @return $this
     */
    public function transactional(int $attempts = 1, ?string $connection = null): static
    {
        $this->middleware['transactional'] = new Transactional($attempts, $connection);

        return $this;
    }

    /**
     * Fail fast while a dependency is broken: after $threshold consecutive
     * failures the breaker opens and handle() throws CircuitOpenException
     * without executing, until $cooldown seconds have passed — then a single
     * probe decides whether it closes again. The key defaults to the action
     * class; give breakers of one shared dependency the same explicit key so
     * they trip together. Nested inside retry(), every attempt consults and
     * feeds the breaker, and CircuitOpenException is NonRetryable so retry()
     * fails fast on an open circuit.
     *
     * @return $this
     */
    public function circuitBreaker(?string $key = null, int $threshold = 5, int $cooldown = 60, ?string $store = null): static
    {
        $this->middleware['circuitBreaker'] = new CircuitBreaker(
            $key ?? $this->action::class, $threshold, $cooldown, $store
        );

        return $this;
    }

    /**
     * Whether the underlying action ran on the last handle() call: null when
     * idempotent() is not configured (or before handle() runs), true if it
     * executed, false if the result was served from the idempotency cache.
     */
    public function wasExecuted(): ?bool
    {
        $idempotency = $this->middleware['idempotent'] ?? null;

        return $idempotency instanceof Idempotency ? $idempotency->wasExecuted() : null;
    }

    /**
     * Intercept handle() and forward any other method call to the wrapped
     * action. handle() itself is virtual on purpose: the class-level
     * `@mixin TAction` gives it the wrapped action's real signature in tools
     * that resolve generic mixins, which a declared handle(mixed ...$args)
     * would shadow.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if ($method === 'handle') {
            return $this->through(fn (): mixed => $this->action->handle(...$arguments), $arguments);
        }

        return $this->action->{$method}(...$arguments);
    }

    /**
     * Execute the action through a closure that receives the wrapped action:
     * the typed alternative to handle() for editors that do not resolve
     * generic mixins yet, with checked arguments and an inferred return type.
     *
     * @template TReturn
     *
     * @param  Closure(TAction): TReturn  $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        // The middleware chain erases the invocation's type (a cached hit or
        // fallback value comes back as mixed), so the result is claimed back
        // as TReturn here — the same trust the @mixin-typed handle() path
        // implies, made explicit at this single boundary.
        /** @var TReturn $result */
        $result = $this->through(fn (): mixed => $callback($this->action), null);

        return $result;
    }

    /**
     * Send the invocation through the configured middleware, nested in the
     * fixed ORDER (outermost first) regardless of chaining order, with the
     * lifecycle events dispatched around the whole chain.
     *
     * @param  Closure(): mixed  $invoke
     * @param  array<array-key, mixed>|null  $args  The handle() arguments, or null on the run() path where no argument list exists.
     */
    protected function through(Closure $invoke, ?array $args): mixed
    {
        $memoize = $this->middleware['memoize'] ?? null;

        if ($memoize instanceof Memoize) {
            $memoize->resolveKey($this->action::class, $args);
        }

        // Wrap innermost to outermost: walking ORDER backwards makes the
        // earliest ORDER entry the outermost layer.
        foreach (array_reverse(static::ORDER) as $slot) {
            $middleware = $this->middleware[$slot] ?? null;

            if ($middleware === null) {
                continue;
            }

            $next = $invoke;
            $invoke = static fn (): mixed => $middleware->handle($next);
        }

        // The events span the whole chain: a cached hit or a rescued run is
        // a completion of the invocation as the caller sees it.
        $startedAt = hrtime(true);
        $memoryBefore = memory_get_usage(true);

        Event::dispatch(new ActionStarted($this->action));

        try {
            $result = $invoke();
        } catch (Throwable $e) {
            Event::dispatch(new ActionFailed(
                $this->action, $e, $this->elapsedMs($startedAt), memory_get_usage(true) - $memoryBefore
            ));

            throw $e;
        }

        Event::dispatch(new ActionCompleted(
            $this->action, $result, $this->elapsedMs($startedAt), memory_get_usage(true) - $memoryBefore
        ));

        return $result;
    }

    /**
     * Milliseconds elapsed since the given hrtime(true) mark.
     */
    protected function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
