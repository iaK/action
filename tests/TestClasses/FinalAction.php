<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;

final class FinalAction extends Action
{
    public function handle(): string
    {
        return 'final';
    }
}
