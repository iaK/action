<?php

namespace Iak\Action\Tests;

use Iak\Action\Action;
use Iak\Action\EmitsEvents;
use Iak\Action\HandlesEvents;

#[EmitsEvents(['test.event.a', 'test.event.b'])]
class MiddleManAction extends Action
{
    public function handle()
    {
        return TestAction::make()
            ->forwardEvents(['test.event.a', 'test.event.b'])
            ->handle();
    }
}
