<?php

namespace Iak\Action\Execution;

use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Per-process idempotency: the first successful run per key is remembered in
 * a container-scoped store and every later call in the same process returns
 * it without executing. No cache store involved, nothing survives the
 * request. Keys derive from the handle() arguments unless one is given
 * explicitly, and are always scoped per action class.
 *
 * @internal Configured via PendingAction::memoize().
 */
class Memoize implements Middleware
{
    protected ?string $resolvedKey = null;

    public function __construct(protected ?string $key = null) {}

    /**
     * Resolve the memo key before the invocation: the explicit key when one
     * was given, otherwise a hash of the invocation arguments. run() passes
     * null (a closure has no argument list to derive a key from), which
     * makes an explicit key mandatory.
     *
     * @param  class-string  $actionClass
     * @param  array<array-key, mixed>|null  $args
     */
    public function resolveKey(string $actionClass, ?array $args): void
    {
        if ($this->key !== null) {
            $this->resolvedKey = 'action.memo:'.$actionClass.':'.$this->key;

            return;
        }

        if ($args === null) {
            throw new InvalidArgumentException(
                'memoize() needs an explicit key when the action is executed through run(): '
                .'a closure has no argument list to derive one from.'
            );
        }

        try {
            $serialized = serialize($args);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                "memoize() could not derive a key from the handle() arguments ({$e->getMessage()}); "
                .'pass an explicit key instead.', previous: $e
            );
        }

        $this->resolvedKey = 'action.memo:'.$actionClass.':args:'.md5($serialized);
    }

    public function handle(Closure $next): mixed
    {
        $key = $this->resolvedKey ?? throw new LogicException('The memoize key was not resolved before the invocation.');

        $store = app(MemoizedResults::class);

        if ($store->has($key)) {
            return $store->get($key);
        }

        $result = $next();

        // Only successful runs are remembered, mirroring idempotent().
        $store->put($key, $result);

        return $result;
    }
}
