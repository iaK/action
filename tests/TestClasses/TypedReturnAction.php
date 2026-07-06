<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;

class TypedReturnAction extends Action
{
    public function handle(string $suffix = ''): string
    {
        return 'typed'.$suffix;
    }
}
