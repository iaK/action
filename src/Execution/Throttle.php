<?php

namespace Iak\Action\Execution;

use Closure;
use Iak\Action\Exceptions\ThrottledException;
use Illuminate\Cache\RateLimiter;
use InvalidArgumentException;

/**
 * Rate-limits the invocations per key: $allow executions per $every seconds,
 * counted through Laravel's RateLimiter. The budget is consumed per real
 * execution attempt — nested inside retry(), every attempt pays — because
 * the throttle protects the dependency behind the action, not the caller.
 *
 * When the budget is exhausted a ThrottledException (carrying availableIn())
 * is thrown instead of blocking; compose retry() with a backoff around the
 * action to wait a window out.
 *
 * @internal Configured via PendingAction::throttle().
 */
class Throttle implements Middleware
{
    public function __construct(
        protected string $key,
        protected int $allow = 60,
        protected int $every = 60,
    ) {
        if ($allow < 1) {
            throw new InvalidArgumentException("throttle() needs to allow at least 1 call, got [{$allow}].");
        }

        if ($every < 1) {
            throw new InvalidArgumentException("throttle() needs a window of at least 1 second, got [{$every}].");
        }
    }

    public function handle(Closure $next): mixed
    {
        $limiter = app(RateLimiter::class);
        $key = $this->limiterKey();

        if ($limiter->tooManyAttempts($key, $this->allow)) {
            throw new ThrottledException($this->key, $limiter->availableIn($key));
        }

        $limiter->hit($key, $this->every);

        return $next();
    }

    protected function limiterKey(): string
    {
        return 'action.throttle:'.$this->key;
    }
}
