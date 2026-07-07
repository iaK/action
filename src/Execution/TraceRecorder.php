<?php

namespace Iak\Action\Execution;

/**
 * Collects trace entries during one invocation, stamping each with its
 * offset from the chain start. Created fresh per traced run by PendingAction
 * and handed to every configured middleware via traceTo(); middleware record
 * through a null-safe `$this->recorder?->record(...)`, so an untraced run
 * costs nothing.
 *
 * @internal
 */
final class TraceRecorder
{
    /** @var list<TraceEntry> */
    protected array $entries = [];

    protected readonly int $startedAt;

    public function __construct()
    {
        $this->startedAt = hrtime(true);
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function record(string $slot, TraceEvent $event, array $context = []): void
    {
        $this->entries[] = new TraceEntry(
            $slot, $event, (hrtime(true) - $this->startedAt) / 1_000_000, $context
        );
    }

    public function finish(): Trace
    {
        return new Trace($this->entries);
    }
}
