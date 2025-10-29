<?php

namespace Iak\Action\Traits;

use Iak\Action\ActionMeasurer;

trait MeasurementProxyTrait
{
    private $measurer;
    private $action;

    public function __construct($testable, $action)
    {
        // Don't call parent constructor - we're using the wrapped action
        $this->measurer = new ActionMeasurer($action);
        $this->action = $action;
        $testable->measuredActions[] = $this->measurer;
    }

    public function handle(...$args)
    {
        return $this->measurer->handle(...$args);
    }
}
