<?php

namespace Iak\Action\Events;

use Iak\Action\Action;

/**
 * Dispatched just before an observed() invocation starts executing. Wrapper
 * features alone never dispatch lifecycle events — observed() is the one
 * opt-in — and plain, unwrapped handle() calls cannot emit them at all (the
 * base Action has no interception point around a user's handle()).
 */
class ActionStarted
{
    public function __construct(
        public readonly Action $action,
    ) {}
}
