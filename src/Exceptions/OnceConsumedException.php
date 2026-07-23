<?php

namespace Iak\Action\Exceptions;

use RuntimeException;

/**
 * Thrown by once() when its key is already consumed: the action did not run
 * and there is no result to answer. A chained fallback() — outermost in the
 * ORDER — catches it and its closure answers instead (rethrow to decline);
 * without a fallback the wrapper converts it to null at the chain boundary,
 * preserving the original skip contract. NonRetryable, because a consumed
 * key cannot un-consume between in-process attempts.
 */
class OnceConsumedException extends RuntimeException implements NonRetryable
{
    public function __construct(protected string $onceKey)
    {
        parent::__construct("The once key [{$onceKey}] is already consumed.");
    }

    /**
     * The consumed key (verbatim — exactly the cache key once() used).
     */
    public function key(): string
    {
        return $this->onceKey;
    }
}
