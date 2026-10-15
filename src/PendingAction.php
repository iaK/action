<?php

namespace Iak\Action;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Events\ActionCompleted;
use Iak\Action\Events\ActionFailed;
use Iak\Action\Events\ActionStarted;
use Iak\Action\Exceptions\OnceConsumedException;
use Iak\Action\Execution\Fallback;
use Iak\Action\Execution\Idempotency;
use Iak\Action\Execution\Memoize;
use Iak\Action\Execution\Middleware;
use Iak\Action\Execution\Once;
use Iak\Action\Execution\Trace;
use Iak\Action\Execution\TraceEvent;
use Iak\Action\Execution\TraceRecorder;
use Iak\Action\Execution\Transactional;
use Iak\Action\Support\Dumper;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

use function Illuminate\Support\defer;

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
 * then() for the same typing through a closure.
 *
 * @template-covariant TAction of Action
 *
 * @mixin TAction
 */
class PendingAction
{
    use Conditionable;

    /**
     * The fixed nesting order of the execution middleware, outermost first.
     * The order the features were chained in never matters — only this array
     * does — so the composition semantics stay predictable and documentable.
     */
    protected const ORDER = [
        'fallback',
        'memoize',
        'idempotent',
        'once',
        'transactional',
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
     * Whether observed() opted this run into the observability envelope —
     * the lifecycle events and the log-context attribution.
     */
    protected bool $observed = false;

    protected bool $tracing = false;

    /** @var (Closure(Trace): void)|null */
    protected ?Closure $traceCallback = null;

    protected ?Trace $lastTrace = null;

    protected bool $dumpsTrace = false;

    protected bool $ddsTrace = false;

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
     * next call executes again. The key is used verbatim as the cache key.
     *
     * @return $this
     */
    public function idempotent(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): static
    {
        $this->middleware['idempotent'] = new Idempotency($key, $ttl, $store);

        return $this;
    }

    /**
     * Run handle() at most once per key, keeping nothing but the key: the
     * first successful run consumes it and every later call is skipped —
     * unlike idempotent() no result is cached or replayed. A skip answers
     * null, or the fallback() value when one is chained: the consumed key
     * surfaces to the fallback closure as an OnceConsumedException (rethrow
     * to decline and the exception reaches the caller). The key is used
     * verbatim as the cache key, and any existing entry under it counts as
     * consumed, whoever wrote it. Only successful runs consume the key; if
     * handle() throws, the key stays free.
     *
     * @return $this
     */
    public function once(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): static
    {
        $this->middleware['once'] = new Once($key, $ttl, $store);

        return $this;
    }

    /**
     * Answer with the closure's value when handle() cannot produce a real
     * result — whatever the reason: the action threw or a consumed once() key
     * (which arrives as an OnceConsumedException). The closure receives the
     * Throwable and may rethrow to decline. The fallback value is never cached
     * as an idempotent result.
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
     * Remember the first successful result per key for the rest of the
     * process (container-scoped, so Octane and the test runner flush it for
     * free) and return it without executing on later calls. The key derives
     * from the handle() arguments — pass one explicitly for unserializable
     * arguments or when executing through then(). Keys are scoped per action
     * class. Flush with Action::flushMemoized().
     *
     * @return $this
     */
    public function memoize(?string $key = null): static
    {
        $this->guardInlineKey('memoize', $key);
        $this->middleware['memoize'] = new Memoize($key);

        return $this;
    }

    /**
     * Opt this run into the observability envelope: the ActionStarted /
     * ActionCompleted / ActionFailed lifecycle events and the log-context
     * attribution. Nothing dispatches them implicitly — the wrapper
     * features do exactly their own job — so adding a transactional() or an
     * idempotency key never changes what your logs or listeners see.
     *
     * @return $this
     */
    public function observed(): static
    {
        $this->observed = true;

        return $this;
    }

    /**
     * Listen for an event emitted by the wrapped action — and keep the
     * chain. Without this, on() would forward through __call and return the
     * action itself, so a wrapper chained after it would silently open a
     * fresh PendingAction and drop everything configured before it.
     * Validation stays in the trait: undeclared events throw.
     *
     * @param  callable(mixed $data): void  $callback
     * @return $this
     */
    public function on(string|UnitEnum $event, callable $callback): static
    {
        $this->action->on($event, $callback);

        return $this;
    }

    /**
     * Enable event forwarding on the wrapped action — and keep the chain,
     * for the same reason on() does. The ancestors are captured here, so
     * call forwardEvents() from within the scope that should receive the
     * events. See HandlesEvents::forwardEvents().
     *
     * @param  array<int, string|UnitEnum>|null  $events  The events to forward; null forwards every event the action declares as allowed.
     * @return $this
     */
    public function forwardEvents(?array $events = null): static
    {
        $this->action->forwardEvents($events);

        return $this;
    }

    /**
     * Record a decision-by-decision trace of the next handle()/then(): what
     * each configured wrapper did and when. Read it afterwards with
     * lastTrace(), receive it in the optional callback — called after the
     * run, also when it throws, which is when a trace matters most — and
     * find it on the lifecycle events (ActionCompleted/ActionFailed::$trace).
     * Costs nothing unless enabled.
     *
     * @param  (Closure(Trace): void)|null  $callback
     * @return $this
     */
    public function trace(?Closure $callback = null): static
    {
        $this->tracing = true;
        $this->traceCallback = $callback;

        return $this;
    }

    /**
     * The trace of the most recent traced run — null before one happened or
     * when tracing is off.
     */
    public function lastTrace(): ?Trace
    {
        return $this->lastTrace;
    }

    /**
     * Print the trace summary once the run finishes — also when it throws.
     * Implies trace(); chain anywhere before handle()/then().
     *
     * @return $this
     */
    public function dumpTrace(): static
    {
        $this->tracing = true;
        $this->dumpsTrace = true;

        return $this;
    }

    /**
     * dumpTrace(), then stop the process — mirroring DB::ddRawSql().
     *
     * @return $this
     */
    public function ddTrace(): static
    {
        $this->tracing = true;
        $this->ddsTrace = true;

        return $this;
    }

    /**
     * Run the action after the response has been sent, via Laravel's
     * defer(). The whole configured wrapper chain runs at that point, not
     * now. The closure receives the wrapped action with full typing — the
     * same shape as then() — because a deferred call has no immediate result
     * to return.
     *
     * @param  Closure(TAction): mixed  $callback
     */
    public function defer(Closure $callback): void
    {
        defer(fn (): mixed => $this->then($callback));
    }

    /**
     * Run handle() inside a database transaction, retried up to $attempts
     * times on a concurrency error (deadlock, serialization failure).
     * Innermost in the nesting order: idempotent() outside it only consumes
     * the key for work that actually committed.
     *
     * @return $this
     */
    public function transactional(int $attempts = 1, ?string $connection = null): static
    {
        $this->middleware['transactional'] = new Transactional($attempts, $connection);

        return $this;
    }

    /**
     * Whether the underlying action ran on the last handle() call: null when
     * neither idempotent() nor once() is configured (or before handle()
     * runs), true if it executed, false if the call was served from the
     * idempotency cache or skipped on a consumed once() key.
     */
    public function wasExecuted(): ?bool
    {
        $idempotency = $this->middleware['idempotent'] ?? null;

        if ($idempotency instanceof Idempotency) {
            return $idempotency->wasExecuted();
        }

        $once = $this->middleware['once'] ?? null;

        return $once instanceof Once ? $once->wasExecuted() : null;
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
            return $this->through(fn (): mixed => $this->action->handle(...$arguments), $arguments);
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
    public function then(Closure $callback): mixed
    {
        // The middleware chain erases the invocation's type (a cached hit or
        // fallback value comes back as mixed), so the result is claimed back
        // as TReturn here — the same trust the @mixin-typed handle() path
        // implies, made explicit at this single boundary.
        /** @var TReturn $result */
        $result = $this->through(fn (): mixed => $callback($this->action), null);

        return $result;
    }

    /**
     * Send the invocation through the configured middleware. An observed()
     * run is additionally attributed in Laravel's log Context for its
     * duration; an unobserved run leaves the context untouched.
     *
     * @param  Closure(): mixed  $invoke
     * @param  array<array-key, mixed>|null  $args  The handle() arguments, or null on the then() path where no argument list exists.
     */
    protected function through(Closure $invoke, ?array $args): mixed
    {
        if (! $this->observed) {
            return $this->throughChain($invoke, $args);
        }

        return ActionContext::within($this->action, fn (): mixed => $this->throughChain($invoke, $args));
    }

    /**
     * Send the invocation through the configured middleware, nested in the
     * fixed ORDER (outermost first) regardless of chaining order, with the
     * lifecycle events dispatched around the whole chain when the run is
     * observed() and the trace recorded when tracing is enabled.
     *
     * @param  Closure(): mixed  $invoke
     * @param  array<array-key, mixed>|null  $args  The handle() arguments, or null on the then() path where no argument list exists.
     */
    protected function throughChain(Closure $invoke, ?array $args): mixed
    {
        $memoize = $this->middleware['memoize'] ?? null;

        if ($memoize instanceof Memoize) {
            $memoize->resolveKey($this->action::class, $args);
        }

        $recorder = $this->tracing ? new TraceRecorder : null;

        // Wrap innermost to outermost: walking ORDER backwards makes the
        // earliest ORDER entry the outermost layer.
        foreach (array_reverse(static::ORDER) as $slot) {
            $middleware = $this->middleware[$slot] ?? null;

            if ($middleware === null) {
                continue;
            }

            if ($recorder !== null) {
                $middleware->traceTo($recorder);
            }

            $next = $invoke;
            $invoke = static fn (): mixed => $middleware->handle($next);
        }

        // A consumed once() key surfaces as an OnceConsumedException so a
        // configured fallback() (outermost) can answer for the skip. Without
        // one, the original contract holds: the skip answers null. Converted
        // inside the event/trace envelope, so a skip stays a completion —
        // never a failure. Installed only when no fallback exists: a fallback
        // that deliberately rethrows the exception must reach the caller.
        if (isset($this->middleware['once']) && ! isset($this->middleware['fallback'])) {
            $next = $invoke;
            $invoke = static function () use ($next): mixed {
                try {
                    return $next();
                } catch (OnceConsumedException) {
                    return null;
                }
            };
        }

        // The events span the whole chain: a cached hit or a rescued run is
        // a completion of the invocation as the caller sees it.
        $startedAt = hrtime(true);
        $memoryBefore = memory_get_usage(true);

        $recorder?->record('action', TraceEvent::Started);

        if ($this->observed) {
            Event::dispatch(new ActionStarted($this->action));
        }

        try {
            $result = $invoke();
        } catch (Throwable $e) {
            $durationMs = $this->elapsedMs($startedAt);
            $recorder?->record('action', TraceEvent::Failed, [
                'exception' => $e::class,
                'duration_ms' => $durationMs,
            ]);
            $trace = $this->finishTrace($recorder);

            if ($this->observed) {
                Event::dispatch(new ActionFailed(
                    $this->action, $e, $durationMs, memory_get_usage(true) - $memoryBefore, $trace
                ));
            }

            $this->reportTrace($trace);

            throw $e;
        }

        $durationMs = $this->elapsedMs($startedAt);
        $recorder?->record('action', TraceEvent::Completed, ['duration_ms' => $durationMs]);
        $trace = $this->finishTrace($recorder);

        if ($this->observed) {
            Event::dispatch(new ActionCompleted(
                $this->action, $result, $durationMs, memory_get_usage(true) - $memoryBefore, $trace
            ));
        }

        $this->reportTrace($trace);

        return $result;
    }

    /**
     * Finalize the recorder into the run's Trace and remember it for
     * lastTrace(). Null when tracing is off.
     */
    protected function finishTrace(?TraceRecorder $recorder): ?Trace
    {
        if ($recorder === null) {
            return null;
        }

        return $this->lastTrace = $recorder->finish();
    }

    /**
     * Hand the finished trace to its consumers: the trace() callback (and,
     * later in the chain of features, the dump helpers). Runs after the
     * lifecycle events, on the success and the failure path alike.
     */
    protected function reportTrace(?Trace $trace): void
    {
        if ($trace === null) {
            return;
        }

        if ($this->traceCallback !== null) {
            ($this->traceCallback)($trace);
        }

        if ($this->ddsTrace) {
            app(Dumper::class)->dd($trace->summary());
        }

        if ($this->dumpsTrace) {
            app(Dumper::class)->dump($trace->summary());
        }
    }

    /**
     * Milliseconds elapsed since the given hrtime(true) mark.
     */
    protected function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    /**
     * Inline actions all share one class, so a class-derived default key
     * would make unrelated inline actions share one memo entry — one inline
     * action's memoized result would answer for every other one. Fail loudly
     * at configuration time instead of silently cross-talking at run time.
     */
    protected function guardInlineKey(string $method, ?string $key): void
    {
        if ($key === null && $this->action instanceof InlineAction) {
            throw new InvalidArgumentException(
                "{$method}() needs an explicit key on an inline action: all inline actions "
                .'share one class, so the class-derived default key would be shared with '
                ."every other inline action in the app. Pass a key: {$method}('my-key')."
            );
        }
    }
}
