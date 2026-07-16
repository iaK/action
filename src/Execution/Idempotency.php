<?php

namespace Iak\Action\Execution;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runs the invocation at most once per idempotency key: the first successful
 * run is cached and every later call with the same key returns that cached
 * result instead of executing again. Only successful runs consume the key —
 * if the invocation throws, the exception propagates and the key stays free.
 *
 * The key is used verbatim as the cache key — no prefix, no per-class
 * scoping — so two actions sharing a key share the entry, and the entry can
 * be inspected or forgotten under exactly the key that was given. Only the
 * result envelope this middleware writes counts as a hit; a foreign value
 * under the key reads as a miss and is overwritten by the next run.
 *
 * @internal Configured via PendingAction::idempotent().
 */
class Idempotency implements Middleware
{
    use TracksTrace;

    /**
     * Whether the underlying action ran on the last invocation: null before
     * the first call, true if it executed, false if the result was cached.
     */
    protected ?bool $executed = null;

    public function __construct(
        protected string $key,
        protected DateInterval|DateTimeInterface|int|null $ttl = null,
        protected ?string $store = null,
    ) {}

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

        // Fast path: serve a cached result without touching a lock.
        [$hit, $result] = $this->lookup($cache);

        if ($hit) {
            $this->executed = false;
            $this->recorder?->record('idempotent', TraceEvent::IdempotencyHit, ['key' => $this->key]);

            return $result;
        }

        $store = $cache->getStore();

        if ($store instanceof LockProvider) {
            return $this->throughLock($cache, $store, $next);
        }

        return $this->execute($cache, $next);
    }

    /**
     * Guard execution with a lock so parallel callers do not double-execute,
     * re-checking the cache once the lock is held (double-checked locking).
     *
     * @param  Closure(): mixed  $next
     */
    protected function throughLock(Repository $cache, LockProvider $store, Closure $next): mixed
    {
        $lock = $store->lock($this->lockKey(), 10);
        $acquired = false;

        try {
            $acquired = $lock->block(10);

            // Another caller may have populated the cache while we waited.
            [$hit, $result] = $this->lookup($cache);

            if ($hit) {
                $this->executed = false;
                $this->recorder?->record('idempotent', TraceEvent::IdempotencyHit, ['key' => $this->key]);

                return $result;
            }

            return $this->execute($cache, $next);
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
    protected function execute(Repository $cache, Closure $next): mixed
    {
        $result = $next();

        $this->persist($cache, ['result' => $result]);
        $this->executed = true;
        $this->recorder?->record('idempotent', TraceEvent::IdempotencyStored, ['key' => $this->key]);

        return $result;
    }

    /**
     * Read the cached envelope. Returns [hit, result]; the envelope wrapper
     * means a stored null/false/'' still counts as a hit (never relying on
     * Cache::get() returning null to signal a miss).
     *
     * @return array{bool, mixed}
     */
    protected function lookup(Repository $cache): array
    {
        $stored = $cache->get($this->key);

        if (is_array($stored) && array_key_exists('result', $stored)) {
            return [true, $stored['result']];
        }

        return [false, null];
    }

    /**
     * Store the result envelope, honouring the configured TTL (forever when
     * no TTL is given).
     *
     * Inside an open database transaction (on the default connection) the
     * write is deferred to the commit: work that rolls back must not consume
     * the key, and a rollback simply discards the deferred write. The window
     * in which a concurrent duplicate can still execute widens by the time
     * to commit — the price of not caching uncommitted work.
     *
     * @param  array{result: mixed}  $envelope
     */
    protected function persist(Repository $cache, array $envelope): void
    {
        $write = function () use ($cache, $envelope): void {
            if ($this->ttl === null) {
                $cache->forever($this->key, $envelope);

                return;
            }

            $cache->put($this->key, $envelope, $this->ttl);
        };

        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($write);

            return;
        }

        $write();
    }

    /**
     * A lock key in its own namespace, so the ephemeral guard lock can never
     * collide with the user's verbatim result key.
     */
    protected function lockKey(): string
    {
        return 'action.idempotent.lock:'.$this->key;
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
