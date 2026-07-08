<?php

namespace Iak\Action\Execution;

/**
 * One recordable decision in an execution trace. The chain-level cases
 * (Started/Completed/Failed) are recorded by PendingAction around the whole
 * middleware chain; every other case belongs to the middleware whose ORDER
 * slot names it. The enum is the single source of truth for what a trace can
 * contain — a new middleware decision means a new case plus its describe()
 * line.
 */
enum TraceEvent: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case FallbackUsed = 'fallback_used';
    case MemoizeHit = 'memoize_hit';
    case IdempotencyHit = 'idempotency_hit';
    case IdempotencyStored = 'idempotency_stored';
    case LockAcquired = 'lock_acquired';
    case RetryAttempt = 'retry_attempt';
    case RetrySlept = 'retry_slept';
    case CircuitOpenRejected = 'circuit_open_rejected';
    case CircuitProbeAllowed = 'circuit_probe_allowed';
    case CircuitTripped = 'circuit_tripped';
    case Throttled = 'throttled';
    case TransactionCommitted = 'transaction_committed';
    case TransactionRetried = 'transaction_retried';

    /**
     * The human-readable summary line for an entry of this event, fed from
     * the entry's context. Missing context keys render as '?' rather than
     * throwing — a summary must never break a debugging session.
     *
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function describe(array $context = []): string
    {
        return match ($this) {
            self::Started => 'started',
            self::Completed => sprintf('completed (%.1fms)', (float) ($context['duration_ms'] ?? 0)),
            self::Failed => sprintf('failed (%s)', (string) ($context['exception'] ?? 'unknown')),
            self::FallbackUsed => sprintf('fallback: answered for %s', (string) ($context['exception'] ?? 'unknown')),
            self::MemoizeHit => 'memoize: served the remembered result',
            self::IdempotencyHit => sprintf("idempotency: served the cached result for '%s'", (string) ($context['key'] ?? '?')),
            self::IdempotencyStored => sprintf("idempotency: result stored for '%s'", (string) ($context['key'] ?? '?')),
            self::LockAcquired => sprintf("lock: acquired for '%s'", (string) ($context['key'] ?? '?')),
            self::RetryAttempt => sprintf('retry: attempt %s failed (%s)', (string) ($context['attempt'] ?? '?'), (string) ($context['exception'] ?? 'unknown')),
            self::RetrySlept => sprintf('retry: sleeping %sms', (string) ($context['milliseconds'] ?? '?')),
            self::CircuitOpenRejected => sprintf('circuit breaker: open, rejecting (available in %ss)', (string) ($context['available_in'] ?? '?')),
            self::CircuitProbeAllowed => 'circuit breaker: half-open, probing',
            self::CircuitTripped => sprintf('circuit breaker: tripped open after %s consecutive failures', (string) ($context['failures'] ?? '?')),
            self::Throttled => sprintf('throttle: budget exhausted (available in %ss)', (string) ($context['available_in'] ?? '?')),
            self::TransactionCommitted => 'transaction: committed',
            self::TransactionRetried => sprintf('transaction: attempt %s after a concurrency error', (string) ($context['attempt'] ?? '?')),
        };
    }
}
