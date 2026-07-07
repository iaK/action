<?php

namespace Iak\Action\Execution;

use Closure;

/**
 * One cross-cutting execution concern wrapped around an action invocation
 * (idempotency, retry, fallback, ...). PendingAction assembles the configured
 * middleware into a chain whose nesting order is fixed by PendingAction::ORDER,
 * regardless of the order the features were chained in.
 *
 * @internal Not a public extension point (yet): the set of middleware is
 * curated by the PendingAction API.
 */
interface Middleware
{
    /**
     * Run the invocation represented by $next, adding this concern's
     * behaviour around (or instead of) it.
     *
     * @param  Closure(): mixed  $next
     */
    public function handle(Closure $next): mixed;

    /**
     * Report this middleware's decisions to the given recorder for the
     * upcoming invocation. PendingAction calls this right before assembling
     * the chain when tracing is enabled; without it, middleware keep a null
     * recorder and record nothing.
     */
    public function traceTo(TraceRecorder $recorder): void;
}
