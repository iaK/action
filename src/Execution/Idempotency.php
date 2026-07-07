<?php

namespace Iak\Action\Execution;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Action;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the invocation at most once per idempotency key: the first successful
 * run is cached and every later call with the same key returns that cached
 * result instead of executing again. Only successful runs consume the key —
 * if the invocation throws, the exception propagates and the key stays free.
 *
 * @internal Configured via PendingAction::idempotent().
 */
class Idempotency implements Middleware
{
    /**
     * Whether the underlying action ran on the last invocation: null before
     * the first call, true if it executed, false if the result was cached.
     */
    protected ?bool $executed = null;

    /**
     * @param  class-string<Action>  $actionClass
     */
    public function __construct(
        protected string $actionClass,
        protected string $key,
        protected DateInterval|DateTimeInterface|int|null $ttl = null,
        protected ?string $store = null,
    ) {}

    /**
     * Build the class-scoped cache key for an idempotency entry, so two actions
     * sharing a user key never collide and forgetIdempotency() can target it.
     *
     * @param  class-string<Action>  $actionClass
     */
    public static function keyFor(string $actionClass, string $key): string
    {
        return 'action.idempotent:'.$actionClass.':'.$key;
    }

    /**
     * Whether the underlying action ran on the last invocation. Null before
     * any invocation, true if it executed, false if served from cache.
     */
    public function wasExecuted(): ?bool
    {
        return $this->executed;
    }

    public function handle(Closure $next): mixed
    {
        $cache = $this->cache();
        $cacheKey = static::keyFor($this->actionClass, $this->key);

        // Fast path: serve a cached result without touching a lock.
        [$hit, $result] = $this->lookup($cache, $cacheKey);

        if ($hit) {
            $this->executed = false;

            return $result;
        }

        $store = $cache->getStore();

        if ($store instanceof LockProvider) {
            return $this->throughLock($cache, $store, $cacheKey, $next);
        }

        return $this->execute($cache, $cacheKey, $next);
    }

    /**
     * Guard execution with a lock so parallel callers do not double-execute,
     * re-checking the cache once the lock is held (double-checked locking).
     *
     * @param  Closure(): mixed  $next
     */
    protected function throughLock(Repository $cache, LockProvider $store, string $cacheKey, Closure $next): mixed
    {
        $lock = $store->lock($this->lockKey(), 10);
        $acquired = false;

        try {
            $acquired = $lock->block(10);

            // Another caller may have populated the cache while we waited.
            [$hit, $result] = $this->lookup($cache, $cacheKey);

            if ($hit) {
                $this->executed = false;

                return $result;
            }

            return $this->execute($cache, $cacheKey, $next);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /**
     * Run the action, cache its result in an envelope and mark it executed.
     *
     * @param  Closure(): mixed  $next
     */
    protected function execute(Repository $cache, string $cacheKey, Closure $next): mixed
    {
        $result = $next();

        $this->persist($cache, $cacheKey, ['result' => $result]);
        $this->executed = true;

        return $result;
    }

    /**
     * Read the cached envelope. Returns [hit, result]; the envelope wrapper
     * means a stored null/false/'' still counts as a hit (never relying on
     * Cache::get() returning null to signal a miss).
     *
     * @return array{bool, mixed}
     */
    protected function lookup(Repository $cache, string $cacheKey): array
    {
        $stored = $cache->get($cacheKey);

        if (is_array($stored) && array_key_exists('result', $stored)) {
            return [true, $stored['result']];
        }

        return [false, null];
    }

    /**
     * Store the result envelope, honouring the configured TTL (forever when
     * no TTL is given).
     *
     * @param  array{result: mixed}  $envelope
     */
    protected function persist(Repository $cache, string $cacheKey, array $envelope): void
    {
        if ($this->ttl === null) {
            $cache->forever($cacheKey, $envelope);

            return;
        }

        $cache->put($cacheKey, $envelope, $this->ttl);
    }

    /**
     * A lock key in its own namespace, so it can never collide with the value
     * key of another user key that happens to end in the lock suffix.
     */
    protected function lockKey(): string
    {
        return 'action.idempotent.lock:'.$this->actionClass.':'.$this->key;
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
