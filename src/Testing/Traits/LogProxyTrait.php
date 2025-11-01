<?php

namespace Iak\Action\Testing\Traits;

use Iak\Action\Testing\LogListener;

trait LogProxyTrait
{
    private $testable;
    private $action;

    public function __construct($testable, $action)
    {
        // Don't call parent constructor - we're using the wrapped action
        $this->testable = $testable;
        $this->action = $action;
    }

    public function handle(...$args)
    {
        // Create a new listener for this specific action
        $listener = new LogListener(get_class($this->action));
        
        $result = $listener->listen(function () use ($args) {
            return $this->action->handle(...$args);
        });
        
        $this->testable->addLogs($listener->getLogs());

        return $result;
    }
}
