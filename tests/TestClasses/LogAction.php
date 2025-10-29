<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;
use Illuminate\Support\Facades\Log;

class LogAction extends Action
{
    public function handle(string $message = 'Hello from LogAction', array $context = [], $level = 'info')
    {
        Log::{$level}($message, $context);

        return $message;
    }
}
