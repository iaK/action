<?php

namespace Iak\Action;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Execution\Trace;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Entry points for inline actions: run a closure through the execution
 * pipeline — idempotency, memoization, tracing, lifecycle events, log context —
 * without declaring an action class. Every configuring static opens a
 * PendingAction around a fresh InlineAction, so the wrappers chain exactly
 * like they do on class-based actions:
 *
 *     Inline::idempotent('sync:'.$user->id)
 *         ->transactional()
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
     * Run the closure immediately, without any wrapper feature. Like every
     * unobserved run it stays silent — no lifecycle events, no log context;
     * start from Inline::observed() to opt a run in.
     *
     * @template TReturn
     *
     * @param  Closure(InlineAction): TReturn  $closure
     * @return TReturn
     */
    public static function handle(Closure $closure): mixed
    {
        // The middleware chain erases the invocation's type; claim it back
        // at this single boundary, mirroring PendingAction::then().
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
     * result of the first successful run afterwards. The key is used
     * verbatim as the cache key. See PendingAction::idempotent().
     *
     * @return PendingAction<InlineAction>
     */
    public static function idempotent(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): PendingAction
    {
        return (new PendingAction(new InlineAction))->idempotent($key, $ttl, $store);
    }

    /**
     * Run the closure at most once per key, keeping nothing but the key —
     * later calls are skipped and answer null, or a chained fallback()'s
     * value (the consumed key arrives as an OnceConsumedException); no
     * result is cached. The key is used verbatim as the cache key. See
     * PendingAction::once().
     *
     * @return PendingAction<InlineAction>
     */
    public static function once(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): PendingAction
    {
        return (new PendingAction(new InlineAction))->once($key, $ttl, $store);
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
     * Answer with the closure's value when the run ultimately throws or a
     * chained once() key is consumed. See PendingAction::fallback().
     *
     * @param  Closure(\Throwable): mixed  $fallback
     * @return PendingAction<InlineAction>
     */
    public static function fallback(Closure $fallback): PendingAction
    {
        return (new PendingAction(new InlineAction))->fallback($fallback);
    }

    /**
     * Remember the first successful result per key for the rest of the
     * process. The key is required: a closure argument list can never derive
     * one. All inline actions share one memo namespace (they share one class) — choose keys accordingly.
     * See PendingAction::memoize().
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
     * Opt the run into the observability envelope — lifecycle events and
     * log-context attribution — exactly like observed() on a class action.
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
     * twin of Action::forgetIdempotency(). The key is forgotten verbatim,
     * exactly the cache key idempotent() and once() use.
     */
    public static function forgetIdempotency(string $key, ?string $store = null): void
    {
        Cache::store($store)->forget($key);
    }
}
