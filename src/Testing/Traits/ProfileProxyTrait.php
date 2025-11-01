<?php

namespace Iak\Action\Testing\Traits;

use Iak\Action\Testing\RuntimeProfiler;

trait ProfileProxyTrait
{
    private $profiler;
    private $action;

    public function __construct($testable, $action)
    {
        // Don't call parent constructor - we're using the wrapped action
        $this->action = $action;
        // Every proxy gets its own profiler and reports independently
        $this->profiler = new RuntimeProfiler($action, $this);
        $testable->profiledActions[] = $this->profiler;
    }

    public function handle(...$args)
    {
        return $this->profiler->handle(...$args);
    }
}

