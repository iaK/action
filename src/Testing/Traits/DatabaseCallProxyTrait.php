<?php

namespace Iak\Action\Testing\Traits;

use Iak\Action\Testing\QueryListener;

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

        return $this->testable->queryListener->listen(function () use ($args) {
            return $this->action->handle(...$args);
        });
    }
}
