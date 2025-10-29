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
        if (!$this->testable->logListener) {
            $this->testable->logListener = new LogListener();
        }

        return $this->testable->logListener->listen(function () use ($args) {
            return $this->action->handle(...$args);
        });
    }
}
