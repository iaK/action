<?php

namespace Iak\Action\Testing\Traits;

use Iak\Action\Action;
use Iak\Action\Testing\Testable;
use Iak\Action\Testing\ProxyConfiguration;

trait ProxyTrait
{
    private Testable $testable;
    private Action $action;
    private ProxyConfiguration $config;

    public function __construct(Testable $testable, Action $action, ProxyConfiguration $config)
    {
        // Don't call parent constructor - we're using the wrapped action
        $this->testable = $testable;
        $this->action = $action;
        $this->config = $config;
    }

    public function handle(...$args)
    {
        // Create listener using the factory callable from configuration
        $createListener = $this->config->createListener;
        $listener = $createListener($this->action, $this);
        
        // Execute the action using the listener
        $result = $listener->listen(function () use ($args) {
            return $this->action->handle(...$args);
        });
        
        // Get results using the callable from configuration
        $getResult = $this->config->getResult;
        $resultData = $getResult($listener);
        
        // Add results to testable using the callable from configuration
        $addResult = $this->config->addResult;
        $addResult($this->testable, $resultData);

        return $result;
    }
}

