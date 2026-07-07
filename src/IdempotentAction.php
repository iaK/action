<?php

namespace Iak\Action;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Wraps an action so that handle() runs at most once per idempotency key: the
 * first successful run is cached and every later call with the same key returns
 * that cached result instead of executing again.
 *
 * This is a production helper (it never binds anything into the container and
 * is not gated by the test-helper guard). It owns the invocation the same way
 * Testable does, because the base Action cannot intercept a user's handle().
 *
 * handle() is intentionally not declared as a real method: the class-level
 * `@mixin TAction` projects the wrapped action's own handle() signature onto
 * this wrapper (typed arguments and return in PHPStan), and __call intercepts
 * the invocation. Editors that do not resolve generic mixins yet can use
 * run() for the same typing through a closure.
 *
 * @template-covariant TAction of Action
 *
 * @mixin TAction
 */
class IdempotentAction
{
    /**
     * @var TAction
     */
    protected readonly Action $action;

    /**
     * Whether the underlying action ran on the last handle() call: null before
     * the first call, true if it executed, false if the result was cached.
     */
    protected ?bool $executed = null;

    /**
     * @param  TAction  $action
     */
    public function __construct(
        Action $action,
        protected string $key,
        protected DateInterval|DateTimeInterface|int|null $ttl = null,
        protected ?string $store = null,
    ) {
        $this->action = $action;
    }

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
     * Intercept handle() and forward any other method call to the wrapped
     * action. handle() itself is virtual on purpose: the class-level
     * `@mixin TAction` gives it the wrapped action's real signature in tools
     * that resolve generic mixins, which a declared handle(mixed ...$args)
     * would shadow.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if ($method === 'handle') {
            return $this->through(fn (): mixed => $this->action->handle(...$arguments));
        }

        return $this->action->{$method}(...$arguments);
    }

    /**
     * Execute the action through a closure that receives the wrapped action:
     * the typed alternative to handle() for editors that do not resolve
     * generic mixins yet, with checked arguments and an inferred return type.
     *
     * @template TReturn
     *
     * @param  Closure(TAction): TReturn  $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        // The cache round-trip erases the stored type, so the cached-hit value
        // is claimed back as TReturn here — the same trust the @mixin-typed
        // handle() path implies, made explicit at this single boundary.
        /** @var TReturn $result */
        $result = $this->through(fn (): mixed => $callback($this->action));

        return $result;
    }

    /**
     * Run the given invocation once for this key, returning the cached result
     * on subsequent calls. Only successful runs are cached: if the invocation
     * throws, the exception propagates and the key is left unconsumed.
     *
     * @param  Closure(): mixed  $invoke
     */
    protected function through(Closure $invoke): mixed
    {
        $cache = $this->cache();
        $cacheKey = static::keyFor($this->action::class, $this->key);

        // Fast path: serve a cached result without touching a lock.
        [$hit, $result] = $this->lookup($cache, $cacheKey);

        if ($hit) {
            $this->executed = false;

            return $result;
        }

        $store = $cache->getStore();

        if ($store instanceof LockProvider) {
            return $this->throughLock($cache, $store, $cacheKey, $invoke);
        }

        return $this->execute($cache, $cacheKey, $invoke);
    }

    /**
     * Whether the underlying action ran on the last handle() call. Null before
     * handle() has been called, true if it executed, false if served from cache.
     */
    public function wasExecuted(): ?bool
    {
        return $this->executed;
    }

    /**
     * Guard execution with a lock so parallel callers do not double-execute,
     * re-checking the cache once the lock is held (double-checked locking).
     *
     * @param  Closure(): mixed  $invoke
     */
    protected function throughLock(Repository $cache, LockProvider $store, string $cacheKey, Closure $invoke): mixed
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

            return $this->execute($cache, $cacheKey, $invoke);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /**
     * Run the action, cache its result in an envelope and mark it executed.
     *
     * @param  Closure(): mixed  $invoke
     */
    protected function execute(Repository $cache, string $cacheKey, Closure $invoke): mixed
    {
        $result = $invoke();

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
        return 'action.idempotent.lock:'.$this->action::class.':'.$this->key;
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
