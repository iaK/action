<?php

namespace Iak\Action;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Execution\Idempotency;
use Iak\Action\Execution\Trace;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Entry points for inline actions: run a closure through the execution
 * pipeline — idempotency, retries, tracing, lifecycle events, log context —
 * without declaring an action class. Every configuring static opens a
 * PendingAction around a fresh InlineAction, so the wrappers chain exactly
 * like they do on class-based actions:
 *
 *     Inline::idempotent('sync:'.$user->id)
 *         ->retry(3, backoff: 100)
 *         ->handle(fn () => $user->sync());
 *
 * The closure receives the InlineAction as its argument. Inline actions do
 * not get constructor injection, #[EmitsEvents] ancestor propagation or
 * Action::test() mocking — promote the closure to a real action class when
 * you need those; its body moves into handle() unchanged.
 */
final class Inline
{
    private function __construct() {}

    /**
     * Run the closure immediately. The run is wrapper-mediated even without
     * wrappers, so — unlike a bare $action->handle() on a class action — it
     * is attributed in log context and dispatches the ActionStarted /
     * ActionCompleted / ActionFailed lifecycle events, like observed().
     *
     * @template TReturn
     *
     * @param  Closure(InlineAction): TReturn  $closure
     * @return TReturn
     */
    public static function handle(Closure $closure): mixed
    {
        // The middleware chain erases the invocation's type; claim it back
        // at this single boundary, mirroring PendingAction::run().
        /** @var TReturn $result */
        $result = (new PendingAction(new InlineAction))->handle($closure);

        return $result;
    }

    /**
     * Run the closure after the response has been sent, via Laravel's
     * defer(). The closure receives the InlineAction, like handle().
     *
     * @param  Closure(InlineAction): mixed  $callback
     */
    public static function defer(Closure $callback): void
    {
        (new PendingAction(new InlineAction))->defer($callback);
    }

    /**
     * Run the closure at most once per idempotency key, returning the cached
     * result of the first successful run afterwards. All inline actions share
     * one key namespace (they share one class) — choose keys accordingly.
     * See PendingAction::idempotent().
     *
     * @return PendingAction<InlineAction>
     */
    public static function idempotent(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): PendingAction
    {
        return (new PendingAction(new InlineAction))->idempotent($key, $ttl, $store);
    }

    /**
     * Declare the events the inline action may emit — the fluent twin of the
     * #[EmitsEvents] attribute, which needs a class to sit on. Declare at
     * the entry (a mid-chain ->events() has nothing to forward to and fails
     * loudly), then listen with ->on() and emit through the closure's action
     * argument:
     *
     *     Inline::events(['report.sent'])
     *         ->on('report.sent', fn ($report) => ...)
     *         ->handle(fn ($action) => $action->event('report.sent', $report));
     *
     * @param  array<int, string|UnitEnum>  $events
     * @return PendingAction<InlineAction>
     */
    public static function events(array $events): PendingAction
    {
        return new PendingAction((new InlineAction)->allowEvents($events));
    }

    /**
     * Answer with the closure's value when the run ultimately throws.
     * See PendingAction::fallback().
     *
     * @param  Closure(\Throwable): mixed  $fallback
     * @return PendingAction<InlineAction>
     */
    public static function fallback(Closure $fallback): PendingAction
    {
        return (new PendingAction(new InlineAction))->fallback($fallback);
    }

    /**
     * Re-run the closure when it throws, up to $times total attempts.
     * See PendingAction::retry().
     *
     * @param  (Closure(int, \Throwable): int)|int|array<int, int>  $backoff
     * @param  (Closure(\Throwable): bool)|null  $when
     * @param  bool  $jitter  Sleep a random duration between zero and the scheduled backoff instead of the exact value.
     * @return PendingAction<InlineAction>
     */
    public static function retry(int $times = 3, Closure|int|array $backoff = 0, ?Closure $when = null, bool $jitter = false): PendingAction
    {
        return (new PendingAction(new InlineAction))->retry($times, $backoff, $when, $jitter);
    }

    /**
     * Fail fast while a dependency is broken. The key is required: inline
     * actions share one class, so the class-derived default would give every
     * inline action in the app the same breaker. See
     * PendingAction::circuitBreaker().
     *
     * @return PendingAction<InlineAction>
     */
    public static function circuitBreaker(string $key, int $threshold = 5, int $cooldown = 60, ?string $store = null): PendingAction
    {
        return (new PendingAction(new InlineAction))->circuitBreaker($key, $threshold, $cooldown, $store);
    }

    /**
     * Rate-limit executions per key. The key is required: inline actions
     * share one class, so the class-derived default would pool every inline
     * action into one budget. See PendingAction::throttle().
     *
     * @return PendingAction<InlineAction>
     */
    public static function throttle(string $key, int $allow = 60, int $every = 60): PendingAction
    {
        return (new PendingAction(new InlineAction))->throttle($key, $allow, $every);
    }

    /**
     * Never run two overlapping executions per key. The key is required:
     * inline actions share one class, so the class-derived default would
     * serialize unrelated inline actions behind one mutex. See
     * PendingAction::withoutOverlapping().
     *
     * @return PendingAction<InlineAction>
     */
    public static function withoutOverlapping(string $key, int $wait = 0, int $staleAfter = 60, ?string $store = null): PendingAction
    {
        return (new PendingAction(new InlineAction))->withoutOverlapping($key, $wait, $staleAfter, $store);
    }

    /**
     * Remember the first successful result per key for the rest of the
     * process. The key is required: a closure argument list can never derive
     * one. See PendingAction::memoize().
     *
     * @return PendingAction<InlineAction>
     */
    public static function memoize(string $key): PendingAction
    {
        return (new PendingAction(new InlineAction))->memoize($key);
    }

    /**
     * Run the closure inside a database transaction.
     * See PendingAction::transactional().
     *
     * @return PendingAction<InlineAction>
     */
    public static function transactional(int $attempts = 1, ?string $connection = null): PendingAction
    {
        return (new PendingAction(new InlineAction))->transactional($attempts, $connection);
    }

    /**
     * Opt the run into the lifecycle events explicitly. Bare Inline::handle()
     * already dispatches them; this exists for symmetry with class actions.
     *
     * @return PendingAction<InlineAction>
     */
    public static function observed(): PendingAction
    {
        return (new PendingAction(new InlineAction))->observed();
    }

    /**
     * Record a decision-by-decision trace of the run.
     * See PendingAction::trace().
     *
     * @param  (Closure(Trace): void)|null  $callback
     * @return PendingAction<InlineAction>
     */
    public static function trace(?Closure $callback = null): PendingAction
    {
        return (new PendingAction(new InlineAction))->trace($callback);
    }

    /**
     * Trace the run and print the summary once it finishes.
     * See PendingAction::dumpTrace().
     *
     * @return PendingAction<InlineAction>
     */
    public static function dumpTrace(): PendingAction
    {
        return (new PendingAction(new InlineAction))->dumpTrace();
    }

    /**
     * dumpTrace(), then stop the process. See PendingAction::ddTrace().
     *
     * @return PendingAction<InlineAction>
     */
    public static function ddTrace(): PendingAction
    {
        return (new PendingAction(new InlineAction))->ddTrace();
    }

    /**
     * Forget the cached idempotency result for the given key — the static
     * twin of Action::forgetIdempotency(), applying the shared
     * InlineAction key scope.
     */
    public static function forgetIdempotency(string $key, ?string $store = null): void
    {
        Cache::store($store)->forget(Idempotency::keyFor(InlineAction::class, $key));
    }
}
