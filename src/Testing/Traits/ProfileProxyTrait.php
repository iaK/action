<?php

namespace Iak\Action\Testing\Traits;

use Iak\Action\Testing\RuntimeProfiler;

trait ProfileProxyTrait
{
    private $testable;
    private $profiler;
    private $action;

    public function __construct($testable, $action)
    {
        // Don't call parent constructor - we're using the wrapped action
        $this->testable = $testable;
        $this->action = $action;
        // Every proxy gets its own profiler and reports independently
        $this->profiler = new RuntimeProfiler($action, $this);
    }

    public function handle(...$args)
    {
        $result = $this->profiler->handle(...$args);
        
        // Save the profile result after execution
        $this->testable->addProfile($this->profiler->result());

        return $result;
    }
}

