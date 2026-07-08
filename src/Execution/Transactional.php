<?php

namespace Iak\Action\Execution;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Runs the invocation inside a database transaction, re-running it up to
 * $attempts times on a concurrency error (deadlock, serialization failure)
 * via DB::transaction(). Innermost in the middleware ORDER on purpose: a
 * retry() around it gives every attempt its own fresh transaction.
 *
 * @internal Configured via PendingAction::transactional().
 */
class Transactional implements Middleware
{
    use TracksTrace;

    public function __construct(
        protected int $attempts = 1,
        protected ?string $connection = null,
    ) {
        if ($attempts < 1) {
            throw new InvalidArgumentException("transactional() needs at least 1 attempt, got [{$attempts}].");
        }
    }

    public function handle(Closure $next): mixed
    {
        $attempt = 0;

        // max() restates the constructor guard in a way PHPStan can see
        // (transaction() wants int<1, max>).
        $result = DB::connection($this->connection)->transaction(function () use ($next, &$attempt): mixed {
            $attempt++;

            if ($attempt > 1) {
                $this->recorder?->record('transactional', TraceEvent::TransactionRetried, ['attempt' => $attempt]);
            }

            return $next();
        }, max(1, $this->attempts));

        $this->recorder?->record('transactional', TraceEvent::TransactionCommitted);

        return $result;
    }
}
