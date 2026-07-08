<?php

namespace Iak\Action\Events;

use Iak\Action\Action;
use Iak\Action\Execution\Trace;
use Throwable;

/**
 * Dispatched when a wrapper-mediated invocation throws (after retries and
 * every other configured layer had their say — a rescued run dispatches
 * ActionCompleted with the fallback value instead). The exception is
 * rethrown to the caller after this event.
 * $trace is the execution trace when tracing was enabled for the invocation,
 * null otherwise.
 */
class ActionFailed
{
    public function __construct(
        public readonly Action $action,
        public readonly Throwable $exception,
        public readonly float $durationMs,
        public readonly int $memoryBytes,
        public readonly ?Trace $trace,
    ) {}
}
