<?php

namespace Iak\Action\Execution;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Exceptions\OnceConsumedException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runs the invocation at most once per key, keeping nothing but the key: the
 * first successful run stores a bare `true` under the verbatim cache key and
 * every later call is skipped — unlike idempotent() there is no result to
 * replay. A skip throws OnceConsumedException, which the outermost Fallback
 * middleware turns into the fallback value, or the wrapper converts to null
 * when no fallback is chained. Any existing entry under the key counts as
 * consumed, whoever wrote it. Only successful runs consume the key — if the
 * invocation throws, the exception propagates and the key stays free.
 *
 * @internal Configured via PendingAction::once().
 */
class Once implements Middleware
{
    use TracksTrace;

    /**
     * Whether the underlying action ran on the last invocation: null before
     * the first call, true if it executed, false if the call was skipped.
     */
    protected ?bool $executed = null;

    public function __construct(
        protected string $key,
        protected DateInterval|DateTimeInterface|int|null $ttl = null,
        protected ?string $store = null,
    ) {}

    /**
     * Whether the underlying action ran on the last invocation. Null before
     * any invocation, true if it executed, false if the call was skipped.
     */
    public function wasExecuted(): ?bool
    {
        return $this->executed;
    }

    public function handle(Closure $next): mixed
    {
        $cache = $this->cache();

        // Fast path: skip a consumed key without touching a lock.
        if ($cache->has($this->key)) {
            $this->skip();
        }

        $store = $cache->getStore();

        if ($store instanceof LockProvider) {
            return $this->throughLock($cache, $store, $next);
        }

        return $this->execute($cache, $next);
    }

    /**
     * Guard execution with a lock so parallel callers do not double-execute,
     * re-checking the key once the lock is held (double-checked locking).
     *
     * @param  Closure(): mixed  $next
     */
    protected function throughLock(Repository $cache, LockProvider $store, Closure $next): mixed
    {
        $lock = $store->lock($this->lockKey(), 10);
        $acquired = false;

        try {
            $acquired = $lock->block(10);

            // Another caller may have consumed the key while we waited.
            if ($cache->has($this->key)) {
                $this->skip();
            }

            return $this->execute($cache, $next);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /**
     * Mark the invocation skipped and signal the consumed key. The throw
     * reaches a chained fallback() — outermost in the ORDER — whose closure
     * answers for the skip, or is converted to null at the chain boundary
     * when no fallback is configured.
     */
    protected function skip(): never
    {
        $this->executed = false;
        $this->recorder?->record('once', TraceEvent::OnceHit, ['key' => $this->key]);

        throw new OnceConsumedException($this->key);
    }

    /**
     * Run the action, consume the key and mark the invocation executed.
     *
     * @param  Closure(): mixed  $next
     */
    protected function execute(Repository $cache, Closure $next): mixed
    {
        $result = $next();

        $this->persist($cache);
        $this->executed = true;
        $this->recorder?->record('once', TraceEvent::OnceStored, ['key' => $this->key]);

        return $result;
    }

    /**
     * Store the marker, honouring the configured TTL (forever when no TTL is
     * given).
     *
     * Inside an open database transaction (on the default connection) the
     * write is deferred to the commit: work that rolls back must not consume
     * the key, and a rollback simply discards the deferred write. The window
     * in which a concurrent duplicate can still execute widens by the time
     * to commit — the price of not consuming the key for uncommitted work.
     */
    protected function persist(Repository $cache): void
    {
        $write = function () use ($cache): void {
            if ($this->ttl === null) {
                $cache->forever($this->key, true);

                return;
            }

            $cache->put($this->key, true, $this->ttl);
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
     * collide with the user's verbatim marker key.
     */
    protected function lockKey(): string
    {
        return 'action.once.lock:'.$this->key;
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
