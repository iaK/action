<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;
use Iak\Action\EmitsEvents;

#[EmitsEvents(['test.event.a', 'test.event.b'])]
class OtherClosureAction extends Action
{
    public function handle(?\Closure $closure = null)
    {
        return $closure ? $closure($this) : null;
    }
}
