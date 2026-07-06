<?php

namespace Iak\Action\Testing;

use Closure;
use Iak\Action\Action;
use Illuminate\Support\Collection;

/**
 * Describes a single instrumentation feature (profiling, query recording or
 * log recording): how to build its listener, how to read that listener's
 * results, plus the per-run configuration and the results gathered so far.
 *
 * One descriptor drives every code path in {@see Testable}: the registrar that
 * configures it, the container interception that proxies nested actions, and
 * the wrapper that instruments the action under test. Adding another feature
 * is therefore a matter of registering one more descriptor rather than copying
 * the machinery a fourth time.
 *
 * The templates are covariant so heterogeneous descriptors can be held
 * together as `Instrumentation<Listener, mixed>`; the accumulated results are
 * stored untyped for the same reason a mutable collection cannot preserve a
 * covariant element type. The exact result type is enforced at the boundaries:
 * the public registrar signatures on {@see Testable} and the listener check in
 * each `readResults` closure.
 *
 * @template-covariant TListener of Listener
 * @template-covariant TResult
 */
class Instrumentation
{
    /**
     * Whether the action under test itself should be instrumented. Set by the
     * callback-only registrar form, e.g. `->profile($callback)`.
     */
    public bool $wrapMainAction = false;

    /**
     * Nested action classes to instrument through container proxies.
     *
     * @var array<int, class-string<Action>>
     */
    public array $actions = [];

    /**
     * The user callback, invoked with a Collection of this feature's results
     * once handle() has run. Held as a bare Closure so the exact generic
     * signature declared on the public registrar (for example
     * `Closure(Collection<int, Query>): void`) stays intact at the API
     * boundary without being threaded through this descriptor's own generics.
     */
    public ?Closure $callback = null;

    /**
     * Results gathered from every listener this feature has run. Stored untyped
     * because a mutable collection cannot preserve the covariant TResult; the
     * concrete item type is guaranteed by the paired readResults closure.
     *
     * @var array<int, mixed>
     */
    protected array $results = [];

    /**
     * @param  Closure(Action, Action): TListener  $createListener  Builds the listener for an action and its event source
     * @param  Closure(Listener): array<int, TResult>  $readResults  Reads the collected results off a finished listener
     */
    public function __construct(
        public readonly Closure $createListener,
        public readonly Closure $readResults,
    ) {}

    /**
     * Append the results read from one listener run.
     *
     * @param  array<int, mixed>  $results
     */
    public function collect(array $results): void
    {
        foreach ($results as $result) {
            $this->results[] = $result;
        }
    }

    /**
     * Invoke the registered callback (if any) with the collected results.
     */
    public function report(): void
    {
        if ($this->callback !== null) {
            ($this->callback)(new Collection($this->results));
        }
    }
}
