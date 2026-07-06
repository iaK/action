<?php

namespace Iak\Action\Testing\Traits;

use Iak\Action\Action;
use Iak\Action\Testing\Listener;
use Iak\Action\Testing\ProxyConfiguration;
use Iak\Action\Testing\Testable;

trait ProxyTrait
{
    /** @var Testable<Action> */
    private Testable $testable;

    private Action $action;

    /** @var ProxyConfiguration<Listener, mixed> */
    private ProxyConfiguration $config;

    /**
     * @param  Testable<Action>  $testable
     * @param  ProxyConfiguration<Listener, mixed>  $config
     */
    public function __construct(Testable $testable, Action $action, ProxyConfiguration $config)
    {
        // Don't call parent constructor - we're using the wrapped action
        $this->testable = $testable;
        $this->action = $action;
        $this->config = $config;
    }

    /**
     * Instrumented replacement for handle(). The generated proxy class
     * defines a handle() override matching the action's own signature and
     * delegates here.
     *
     * @param  mixed  ...$args
     */
    protected function proxyHandle(...$args): mixed
    {
        // Create listener using the factory callable from configuration
        $listener = ($this->config->createListener)($this->action, $this);

        // Execute the action using the listener
        $result = $listener->listen(function () use ($args) {
            return $this->action->handle(...$args);
        });

        // Get results using the callable from configuration
        $resultData = ($this->config->getResult)($listener);

        // Add results to testable using the callable from configuration
        ($this->config->addResult)($this->testable, $resultData);

        return $result;
    }
}
