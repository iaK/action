<?php

namespace Iak\Action\Execution;

use Closure;
use Throwable;

/**
 * Answers with a fallback value when the invocation cannot produce a real
 * result — whatever went wrong. It sits outermost in the middleware ORDER on
 * purpose: it catches exhausted retries, open circuit breakers, lock
 * timeouts and consumed once() keys (OnceConsumedException) alike, and its
 * value can never be mistaken for a real result by the caching layers nested
 * inside it. Rethrow (or throw anew) from the fallback to decline.
 *
 * @internal Configured via PendingAction::fallback().
 */
class Fallback implements Middleware
{
    use TracksTrace;

    /**
     * @param  Closure(Throwable): mixed  $fallback
     */
    public function __construct(protected Closure $fallback) {}

    public function handle(Closure $next): mixed
    {
        try {
            return $next();
        } catch (Throwable $e) {
            $this->recorder?->record('fallback', TraceEvent::FallbackUsed, ['exception' => $e::class]);

            return ($this->fallback)($e);
        }
    }
}
