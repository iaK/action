<?php

namespace Iak\Action\Execution;

use Closure;
use Iak\Action\Exceptions\CircuitOpenException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Fails fast while a dependency is broken: after $threshold consecutive
 * failures the breaker opens and every call throws CircuitOpenException
 * without executing, until $cooldown seconds have passed — then one probe
 * runs (half-open) and its outcome closes or re-opens the breaker.
 *
 * The failure streak is cache-backed and shared by everything using the same
 * key, so several processes trip and honour the same breaker. A success
 * clears the streak. Streaks below the threshold persist until a success
 * clears them; with consecutive-failure semantics that only trips early for
 * a dependency that never succeeded in between, which is the point.
 *
 * @internal Configured via PendingAction::circuitBreaker().
 */
class CircuitBreaker implements Middleware
{
    public function __construct(
        protected string $key,
        protected int $threshold = 5,
        protected int $cooldown = 60,
        protected ?string $store = null,
    ) {
        if ($threshold < 1) {
            throw new InvalidArgumentException("circuitBreaker() needs a threshold of at least 1, got [{$threshold}].");
        }

        if ($cooldown < 1) {
            throw new InvalidArgumentException("circuitBreaker() needs a cooldown of at least 1 second, got [{$cooldown}].");
        }
    }

    public function handle(Closure $next): mixed
    {
        $cache = $this->cache();
        $state = $this->state($cache);

        if ($state['opened_at'] !== null) {
            $availableIn = $state['opened_at'] + $this->cooldown - Carbon::now()->getTimestamp();

            if ($availableIn > 0) {
                throw new CircuitOpenException($this->key, $availableIn);
            }

            return $this->probe($cache, $state, $next);
        }

        return $this->attempt($cache, $state, $next);
    }

    /**
     * Run the single half-open probe. On stores with locks, concurrent
     * callers contend for the probe slot and the losers are told the circuit
     * is still open, so a recovering dependency meets one request, not a
     * thundering herd.
     *
     * @param  array{failures: int, opened_at: int|null}  $state
     * @param  Closure(): mixed  $next
     */
    protected function probe(Repository $cache, array $state, Closure $next): mixed
    {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->attempt($cache, $state, $next);
        }

        $lock = $store->lock($this->lockKey(), 10);

        if (! $lock->get()) {
            throw new CircuitOpenException($this->key, 1);
        }

        try {
            return $this->attempt($cache, $state, $next);
        } finally {
            $lock->release();
        }
    }

    /**
     * Execute the invocation, recording the outcome: a success closes the
     * breaker and clears the streak, a failure extends it (and opens the
     * breaker at the threshold) before propagating.
     *
     * @param  array{failures: int, opened_at: int|null}  $state
     * @param  Closure(): mixed  $next
     */
    protected function attempt(Repository $cache, array $state, Closure $next): mixed
    {
        try {
            $result = $next();
        } catch (Throwable $e) {
            $this->recordFailure($cache, $state['failures'] + 1);

            throw $e;
        }

        $cache->forget($this->cacheKey());

        return $result;
    }

    protected function recordFailure(Repository $cache, int $failures): void
    {
        $cache->forever($this->cacheKey(), [
            'failures' => $failures,
            'opened_at' => $failures >= $this->threshold ? Carbon::now()->getTimestamp() : null,
        ]);
    }

    /**
     * Read the breaker state, tolerating a missing or malformed entry.
     *
     * @return array{failures: int, opened_at: int|null}
     */
    protected function state(Repository $cache): array
    {
        $stored = $cache->get($this->cacheKey());

        if (is_array($stored) && is_int($stored['failures'] ?? null)) {
            $openedAt = $stored['opened_at'] ?? null;

            return [
                'failures' => $stored['failures'],
                'opened_at' => is_int($openedAt) ? $openedAt : null,
            ];
        }

        return ['failures' => 0, 'opened_at' => null];
    }

    protected function cacheKey(): string
    {
        return 'action.breaker:'.$this->key;
    }

    /**
     * A lock key in its own namespace, so it can never collide with the
     * state key of another breaker key that happens to end in the suffix.
     */
    protected function lockKey(): string
    {
        return 'action.breaker.probe:'.$this->key;
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
