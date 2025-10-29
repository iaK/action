<?php

namespace Iak\Action\Testing\Traits;

use Iak\Action\Testing\RuntimeMeasurer;

trait MeasurementProxyTrait
{
    private $measurer;
    private $action;

    public function __construct($testable, $action)
    {
        // Don't call parent constructor - we're using the wrapped action
        $this->measurer = new RuntimeMeasurer($action);
        $this->action = $action;
        $testable->measuredActions[] = $this->measurer;
    }

    public function handle(...$args)
    {
        return $this->measurer->handle(...$args);
    }
}
