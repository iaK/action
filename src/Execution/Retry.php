<?php

namespace Iak\Action\Execution;

use Closure;
use Iak\Action\Exceptions\NonRetryable;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

/**
 * Re-runs a failing invocation up to a total number of attempts, sleeping the
 * configured backoff between attempts. NonRetryable exceptions stop the
 * retrying immediately unless an explicit when filter overrules the marker.
 *
 * @internal Configured via PendingAction::retry().
 */
class Retry implements Middleware
{
    /**
     * @param  int  $times  Total attempts, including the first one.
     * @param  (Closure(int, Throwable): int)|int|array<int, int>  $backoff  Milliseconds to sleep between attempts: a fixed value, a per-attempt schedule (the last entry repeats), or a closure receiving the attempt number and the exception.
     * @param  (Closure(Throwable): bool)|null  $when  Decides entirely whether an exception is retried; null falls back to "everything except NonRetryable".
     * @param  bool  $jitter  Sleep a random duration between zero and the scheduled backoff instead of the exact value, so many processes retrying together spread out instead of arriving in synchronized waves.
     */
    public function __construct(
        protected int $times,
        protected Closure|int|array $backoff = 0,
        protected ?Closure $when = null,
        protected bool $jitter = false,
    ) {
        if ($times < 1) {
            throw new InvalidArgumentException("retry() needs at least one attempt, got [{$times}].");
        }
    }

    public function handle(Closure $next): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $next();
            } catch (Throwable $e) {
                if ($attempt >= $this->times || ! $this->shouldRetry($e)) {
                    throw $e;
                }

                $this->sleep($attempt, $e);
            }
        }
    }

    protected function shouldRetry(Throwable $e): bool
    {
        if ($this->when !== null) {
            return (bool) ($this->when)($e);
        }

        return ! $e instanceof NonRetryable;
    }

    /**
     * Sleep the backoff configured for the given (failed) attempt. Uses
     * Sleep::for(), so tests control time with Sleep::fake(). With jitter, a
     * positive scheduled backoff becomes random_int(0, backoff) — a zero
     * schedule never sleeps, jitter or not.
     */
    protected function sleep(int $attempt, Throwable $e): void
    {
        $milliseconds = $this->backoffFor($attempt, $e);

        if ($milliseconds <= 0) {
            return;
        }

        if ($this->jitter) {
            $milliseconds = random_int(0, $milliseconds);
        }

        Sleep::for($milliseconds)->milliseconds();
    }

    protected function backoffFor(int $attempt, Throwable $e): int
    {
        if ($this->backoff instanceof Closure) {
            return (int) ($this->backoff)($attempt, $e);
        }

        if (is_int($this->backoff)) {
            return $this->backoff;
        }

        $schedule = array_values($this->backoff);

        if ($schedule === []) {
            return 0;
        }

        return $schedule[min($attempt, count($schedule)) - 1];
    }
}
