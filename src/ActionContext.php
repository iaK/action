<?php

namespace Iak\Action;

use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Attributes a running invocation in Laravel's log Context: while the
 * invocation runs, the 'action' key holds the action's class, so every log
 * line written inside it — including code it calls — carries which action
 * produced it. Nested runs overwrite while inner and restore on exit, so
 * attribution always points at the innermost running action.
 *
 * Uses only Context::add()/get()/forget() — Context::pop() may postdate the
 * illuminate/support ^11.23 floor. A pre-existing user value under 'action'
 * is restored afterwards; a user-set literal null is indistinguishable from
 * an absent key and is forgotten instead.
 *
 * @internal Wrapped around observed() invocations (PendingAction) and every
 * Testable run. Unobserved runs — wrapper features without observed(), and
 * bare $action->handle() calls — leave the context untouched.
 */
final class ActionContext
{
    /**
     * @param  Closure(): mixed  $invoke
     */
    public static function within(Action $action, Closure $invoke): mixed
    {
        $previous = Context::get('action');

        Context::add('action', $action::class);

        try {
            return $invoke();
        } finally {
            $previous === null
                ? Context::forget('action')
                : Context::add('action', $previous);
        }
    }
}
