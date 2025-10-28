<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;

class MultiArgAction extends Action
{
    public function handle($arg1, $arg2, $arg3 = 'default')
    {
        return $arg1 . ' ' . $arg2 . ' ' . $arg3;
    }
}
