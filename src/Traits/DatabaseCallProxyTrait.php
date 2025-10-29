<?php

namespace Iak\Action\Traits;

use Iak\Action\QueryListener;

trait DatabaseCallProxyTrait
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
        if (!$this->testable->queryListener) {
            $this->testable->queryListener = new QueryListener();
        }

        return $this->testable->queryListener->whileEnabled(function () use ($args) {
            return $this->action->handle(...$args);
        });
    }
}
