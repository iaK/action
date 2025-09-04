<?php

namespace Iak\Action\Tests;

use Iak\Action\Action;
use Iak\Action\EmitsEvents;

#[EmitsEvents(['test.event.a', 'test.event.b'])]
class TestAction extends Action
{
    public function __construct(
        private bool $fireIllegalEvent = false
    )
    {
    }

    public function handle()
    {
        $this->event('test.event.a', 'Hello, World!');
        $this->event('test.event.b', ['Hello', 'World']);

        if ($this->fireIllegalEvent) {
            $this->event('test.event.c', 'Hello, World!');
        }

        return 'Hello, World!';
    }
}
