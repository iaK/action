<?php

namespace Iak\Action\Execution;

use Stringable;

/**
 * The recorded decision timeline of one wrapper-mediated invocation: what
 * each configured execution wrapper did and when, in chronological order.
 * Read it after the run via PendingAction::lastTrace(), the trace()
 * callback, or the lifecycle events (ActionCompleted/ActionFailed::$trace).
 */
final class Trace implements Stringable
{
    /**
     * @param  list<TraceEntry>  $entries
     */
    public function __construct(protected readonly array $entries) {}

    /**
     * @return list<TraceEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Whether an entry with the given event was recorded.
     */
    public function has(TraceEvent $event): bool
    {
        return $this->first($event) !== null;
    }

    /**
     * How many entries with the given event were recorded.
     */
    public function count(TraceEvent $event): int
    {
        return count(array_filter(
            $this->entries,
            static fn (TraceEntry $entry): bool => $entry->event === $event
        ));
    }

    /**
     * The first entry with the given event, null when none was recorded.
     */
    public function first(TraceEvent $event): ?TraceEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->event === $event) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Milliseconds from the chain start to the last recorded entry.
     */
    public function durationMs(): float
    {
        return $this->entries === []
            ? 0.0
            : $this->entries[array_key_last($this->entries)]->atMs;
    }

    /**
     * The human-readable timeline, one line per entry:
     *
     *     +0.0ms  started
     *     +0.2ms  retry: attempt 1 failed (ConnectionException)
     *     ...
     */
    public function summary(): string
    {
        return implode(PHP_EOL, array_map(
            static fn (TraceEntry $entry): string => sprintf('+%.1fms  %s', $entry->atMs, $entry->describe()),
            $this->entries
        ));
    }

    public function __toString(): string
    {
        return $this->summary();
    }
}
