<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\EmitsEvents;
use Iak\Action\HandlesEvents;

#[EmitsEvents(['test.event.a', 'test.event.b'])]
class DeeplyNestedAction
{
    use HandlesEvents;

    public function handle()
    {
        $c = new class {
            public function handle()
            {
                MiddleManAction::make()
                    ->forwardEvents(['test.event.a', 'test.event.b'])
                    ->handle();
            }
        };

        return $c->handle();
    }
}
