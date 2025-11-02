<?php

namespace Iak\Action\Testing;

interface Listener
{
    /**
     * Listen to a callback execution and return the result
     */
    public function listen(callable $callback): mixed;
}

