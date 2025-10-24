<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;
use Iak\Action\EmitsEvents;
use Iak\Action\HandlesEvents;

#[EmitsEvents(['test.event.a', 'test.event.b'])]
class MiddleManAction extends Action
{
    public function handle()
    {
        SecondAction::make()
            ->handle();

        return TestAction::make()
            ->forwardEvents(['test.event.a', 'test.event.b'])
            ->handle();
    }
}
