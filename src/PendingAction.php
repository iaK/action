<?php

namespace Iak\Action;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Execution\Idempotency;
use Iak\Action\Execution\Middleware;

/**
 * Wraps an action with cross-cutting execution semantics — idempotency and
 * friends — composed as a middleware chain. Every configuring method returns
 * the same wrapper, so the features chain freely.
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
class PendingAction
{
    /**
     * The fixed nesting order of the execution middleware, outermost first.
     * The order the features were chained in never matters — only this array
     * does — so the composition semantics stay predictable and documentable.
     */
    protected const ORDER = [
        'idempotent',
    ];

    /**
     * @var TAction
     */
    protected readonly Action $action;

    /**
     * The configured middleware, keyed by their ORDER slot.
     *
     * @var array<string, Middleware>
     */
    protected array $middleware = [];

    /**
     * @param  TAction  $action
     */
    public function __construct(Action $action)
    {
        $this->action = $action;
    }

    /**
     * Run handle() at most once per idempotency key, returning the cached
     * result of the first successful run afterwards. Only successful runs
     * consume the key; if handle() throws, the exception propagates and the
     * next call executes again. Keys are scoped per action class.
     *
     * @return $this
     */
    public function idempotent(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): static
    {
        $this->middleware['idempotent'] = new Idempotency($this->action::class, $key, $ttl, $store);

        return $this;
    }

    /**
     * Whether the underlying action ran on the last handle() call: null when
     * idempotent() is not configured (or before handle() runs), true if it
     * executed, false if the result was served from the idempotency cache.
     */
    public function wasExecuted(): ?bool
    {
        $idempotency = $this->middleware['idempotent'] ?? null;

        return $idempotency instanceof Idempotency ? $idempotency->wasExecuted() : null;
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
        // The middleware chain erases the invocation's type (a cached hit or
        // fallback value comes back as mixed), so the result is claimed back
        // as TReturn here — the same trust the @mixin-typed handle() path
        // implies, made explicit at this single boundary.
        /** @var TReturn $result */
        $result = $this->through(fn (): mixed => $callback($this->action));

        return $result;
    }

    /**
     * Send the invocation through the configured middleware, nested in the
     * fixed ORDER (outermost first) regardless of chaining order.
     *
     * @param  Closure(): mixed  $invoke
     */
    protected function through(Closure $invoke): mixed
    {
        // Wrap innermost to outermost: walking ORDER backwards makes the
        // earliest ORDER entry the outermost layer.
        foreach (array_reverse(static::ORDER) as $slot) {
            $middleware = $this->middleware[$slot] ?? null;

            if ($middleware === null) {
                continue;
            }

            $next = $invoke;
            $invoke = static fn (): mixed => $middleware->handle($next);
        }

        return $invoke();
    }
}
