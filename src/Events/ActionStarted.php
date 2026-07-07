<?php

namespace Iak\Action\Events;

use Iak\Action\Action;

/**
 * Dispatched just before a wrapper-mediated invocation starts executing.
 * Plain, unwrapped handle() calls cannot emit lifecycle events (the base
 * Action has no interception point around a user's handle()); wrap the call
 * — with any feature, or a bare observed() — to opt it in.
 */
class ActionStarted
{
    public function __construct(
        public readonly Action $action,
    ) {}
}
