<?php

namespace Iak\Action;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Execution\CircuitBreaker;
use Iak\Action\Execution\Fallback;
use Iak\Action\Execution\Idempotency;
use Iak\Action\Execution\Middleware;
use Iak\Action\Execution\Retry;
use Throwable;

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
        'fallback',
        'idempotent',
        'retry',
        'circuitBreaker',
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
     * Answer with the closure's value when handle() ultimately throws —
     * whatever went wrong: the action itself, exhausted retries, an open
     * circuit breaker. The closure receives the Throwable and may rethrow to
     * decline. The fallback value is never cached as an idempotent result.
     *
     * @param  Closure(Throwable): mixed  $fallback
     * @return $this
     */
    public function fallback(Closure $fallback): static
    {
        $this->middleware['fallback'] = new Fallback($fallback);

        return $this;
    }

    /**
     * Re-run handle() when it throws, up to $times total attempts, sleeping
     * the given backoff (milliseconds; a fixed value, a per-attempt schedule
     * whose last entry repeats, or a closure receiving the attempt number and
     * the exception) between attempts. $when decides which exceptions are
     * retried; by default everything is except NonRetryable ones.
     *
     * Retrying nests inside idempotent(): failed attempts never consume the
     * key, and the first successful attempt is the result that gets cached.
     *
     * @param  (Closure(int, Throwable): int)|int|array<int, int>  $backoff
     * @param  (Closure(Throwable): bool)|null  $when
     * @return $this
     */
    public function retry(int $times = 3, Closure|int|array $backoff = 0, ?Closure $when = null): static
    {
        $this->middleware['retry'] = new Retry($times, $backoff, $when);

        return $this;
    }

    /**
     * Fail fast while a dependency is broken: after $threshold consecutive
     * failures the breaker opens and handle() throws CircuitOpenException
     * without executing, until $cooldown seconds have passed — then a single
     * probe decides whether it closes again. The key defaults to the action
     * class; give breakers of one shared dependency the same explicit key so
     * they trip together. Nested inside retry(), every attempt consults and
     * feeds the breaker, and CircuitOpenException is NonRetryable so retry()
     * fails fast on an open circuit.
     *
     * @return $this
     */
    public function circuitBreaker(?string $key = null, int $threshold = 5, int $cooldown = 60, ?string $store = null): static
    {
        $this->middleware['circuitBreaker'] = new CircuitBreaker(
            $key ?? $this->action::class, $threshold, $cooldown, $store
        );

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
