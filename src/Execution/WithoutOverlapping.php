<?php

namespace Iak\Action\Execution;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mutex semantics: every call runs eventually, but never two at once per
 * key. Unlike idempotent() nothing is cached — a held lock means waiting up
 * to $wait seconds (or failing immediately at zero) with a
 * LockTimeoutException when the lock never frees. $staleAfter caps how long
 * a crashed holder can keep the lock.
 *
 * The lock IS the feature here, so a cache store without lock support is
 * rejected loudly instead of silently running unguarded.
 *
 * @internal Configured via PendingAction::withoutOverlapping().
 */
class WithoutOverlapping implements Middleware
{
    public function __construct(
        protected string $key,
        protected int $wait = 0,
        protected int $staleAfter = 60,
        protected ?string $store = null,
    ) {
        if ($wait < 0) {
            throw new InvalidArgumentException("withoutOverlapping() needs a non-negative wait, got [{$wait}].");
        }

        if ($staleAfter < 1) {
            throw new InvalidArgumentException("withoutOverlapping() needs a staleAfter of at least 1 second, got [{$staleAfter}].");
        }
    }

    public function handle(Closure $next): mixed
    {
        $store = Cache::store($this->store)->getStore();

        if (! $store instanceof LockProvider) {
            $name = $this->store ?? 'default';

            throw new RuntimeException(
                "withoutOverlapping() needs a cache store that supports locks, but the [{$name}] store does not. "
                .'Use a lock-capable store (redis, memcached, database, file, array, ...) or pass one via $store.'
            );
        }

        $lock = $store->lock($this->lockKey(), $this->staleAfter);

        if ($this->wait > 0) {
            // block() acquires or throws LockTimeoutException on its own.
            $lock->block($this->wait);
        } elseif (! $lock->get()) {
            throw new LockTimeoutException(
                "Another run of the [{$this->key}] action holds the overlap lock."
            );
        }

        try {
            return $next();
        } finally {
            $lock->release();
        }
    }

    /**
     * A lock key in its own namespace, so it can never collide with the
     * cache entries of the other execution wrappers.
     */
    protected function lockKey(): string
    {
        return 'action.overlap:'.$this->key;
    }
}
