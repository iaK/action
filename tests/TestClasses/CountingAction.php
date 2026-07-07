<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;

class CountingAction extends Action
{
    public static int $runs = 0;

    public function handle(int $value = 0): int
    {
        static::$runs++;

        return $value * 2;
    }
}
