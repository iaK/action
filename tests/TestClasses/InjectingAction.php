<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;

class InjectingAction extends Action
{
    public function __construct(
        protected TypedReturnAction $child
    ) {}

    public function handle(): string
    {
        return $this->child->handle();
    }
}
