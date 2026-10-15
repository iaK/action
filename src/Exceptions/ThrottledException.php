<?php

namespace Iak\Action\Exceptions;

use RuntimeException;

/**
 * Thrown instead of executing an action when its throttle() budget for the
 * current window is exhausted. availableIn() tells the caller how long
 * until the window resets, so it can decide whether to wait it out.
 */
class ThrottledException extends RuntimeException
{
    public function __construct(
        protected string $throttleKey,
        protected int $availableIn,
    ) {
        parent::__construct(
            "The action throttle [{$throttleKey}] is exhausted; available again in {$availableIn} seconds."
        );
    }

    /**
     * The throttle key that is exhausted (the action class, unless a key was given).
     */
    public function key(): string
    {
        return $this->throttleKey;
    }

    /**
     * Seconds until the throttle frees up again.
     */
    public function availableIn(): int
    {
        return $this->availableIn;
    }
}
