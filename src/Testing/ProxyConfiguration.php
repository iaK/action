<?php

namespace Iak\Action\Testing;

use Closure;
use Iak\Action\Action;

/**
 * Wires a proxy class to the listener that instruments it and the
 * testable that collects the results.
 *
 * @template TListener of Listener
 * @template TResult
 */
class ProxyConfiguration
{
    /**
     * @param  Closure(Action, Action): TListener  $createListener  Creates the listener for the proxied action
     * @param  Closure(Testable<Action>, TResult): void  $addResult  Adds the listener's result to the testable
     * @param  Closure(TListener): TResult  $getResult  Extracts the result from the listener
     */
    public function __construct(
        public readonly Closure $createListener,
        public readonly Closure $addResult,
        public readonly Closure $getResult
    ) {}
}
