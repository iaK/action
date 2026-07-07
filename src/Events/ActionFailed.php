<?php

namespace Iak\Action\Events;

use Iak\Action\Action;
use Throwable;

/**
 * Dispatched when a wrapper-mediated invocation throws (after retries and
 * every other configured layer had their say — a rescued run dispatches
 * ActionCompleted with the fallback value instead). The exception is
 * rethrown to the caller after this event.
 */
class ActionFailed
{
    public function __construct(
        public readonly Action $action,
        public readonly Throwable $exception,
        public readonly float $durationMs,
        public readonly int $memoryBytes,
    ) {}
}
