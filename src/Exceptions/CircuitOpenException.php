<?php

namespace Iak\Action\Exceptions;

use RuntimeException;

/**
 * Thrown instead of executing an action while its circuit breaker is open:
 * the dependency behind the breaker key failed too many times in a row and
 * is being given time to recover. NonRetryable, so retry() fails fast on it
 * by default — an in-process retry cannot outwait a cooldown.
 */
class CircuitOpenException extends RuntimeException implements NonRetryable
{
    public function __construct(
        protected string $breakerKey,
        protected int $availableIn,
    ) {
        parent::__construct(
            "The circuit breaker [{$breakerKey}] is open; available again in {$availableIn} seconds."
        );
    }

    /**
     * The breaker key that is open (the action class, unless a key was given).
     */
    public function key(): string
    {
        return $this->breakerKey;
    }

    /**
     * Seconds until the breaker lets a probe through again.
     */
    public function availableIn(): int
    {
        return $this->availableIn;
    }
}
