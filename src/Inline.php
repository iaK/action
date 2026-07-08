<?php

namespace Iak\Action;

use Closure;

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
}
