<?php

namespace Iak\Action\Execution;

/**
 * One recorded decision: which ORDER slot made it ('action' for the
 * chain-level entries), what happened, when (milliseconds since the chain
 * started) and the decision's context (attempt numbers, keys, exception
 * classes — scalars only, so a trace is always cheap and serialization-safe).
 */
final class TraceEntry
{
    /**
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function __construct(
        public readonly string $slot,
        public readonly TraceEvent $event,
        public readonly float $atMs,
        public readonly array $context = [],
    ) {}

    /**
     * The human-readable summary line for this entry.
     */
    public function describe(): string
    {
        return $this->event->describe($this->context);
    }
}
