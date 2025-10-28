<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;

class SayHelloAction extends Action
{
    public function handle()
    {
        return 'Hello, World!';
    }
}
