<?php

namespace Iak\Action\Testing;

class ProxyConfiguration
{
    /**
     * @param callable $createListener Callable that takes ($action, $eventSource) and returns a Listener
     * @param callable $addResult Callable that takes ($testable, $resultData) and adds the result to testable
     * @param callable $getResult Callable that takes ($listener) and returns the result data
     */
    public function __construct(
        public readonly mixed $createListener,
        public readonly mixed $addResult,
        public readonly mixed $getResult
    ) {}
}

