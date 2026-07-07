<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;
use Iak\Action\EmitsEvents;

#[EmitsEvents(OrderEvent::class)]
class EnumEventAction extends Action
{
    public function handle(?\Closure $closure = null)
    {
        return $closure ? $closure($this) : null;
    }
}
