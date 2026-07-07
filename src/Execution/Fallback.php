<?php

namespace Iak\Action\Execution;

use Closure;
use Throwable;

/**
 * Answers with a fallback value when the invocation throws — whatever went
 * wrong. It sits outermost in the middleware ORDER on purpose: it catches
 * exhausted retries, open circuit breakers and lock timeouts alike, and its
 * value can never be mistaken for a real result by the caching layers nested
 * inside it. Rethrow (or throw anew) from the fallback to decline.
 *
 * @internal Configured via PendingAction::fallback().
 */
class Fallback implements Middleware
{
    /**
     * @param  Closure(Throwable): mixed  $fallback
     */
    public function __construct(protected Closure $fallback) {}

    public function handle(Closure $next): mixed
    {
        try {
            return $next();
        } catch (Throwable $e) {
            return ($this->fallback)($e);
        }
    }
}
