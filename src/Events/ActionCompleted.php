<?php

namespace Iak\Action\Events;

use Iak\Action\Action;
use Iak\Action\Execution\Trace;

/**
 * Dispatched when a wrapper-mediated invocation finishes without throwing.
 * $result is what the caller receives — a cached idempotent result or a
 * fallback value count as completions of the invocation as a whole.
 * $memoryBytes is the memory_get_usage() delta across the invocation and can
 * be negative when the run frees more than it allocates.
 * $trace is the execution trace when tracing was enabled for the invocation,
 * null otherwise.
 */
class ActionCompleted
{
    public function __construct(
        public readonly Action $action,
        public readonly mixed $result,
        public readonly float $durationMs,
        public readonly int $memoryBytes,
        public readonly ?Trace $trace,
    ) {}
}
