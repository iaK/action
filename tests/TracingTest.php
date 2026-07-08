<?php

use Iak\Action\Events\ActionCompleted;
use Iak\Action\Exceptions\CircuitOpenException;
use Iak\Action\Exceptions\ThrottledException;
use Iak\Action\Execution\Trace;
use Iak\Action\Execution\TraceEvent;
use Iak\Action\Support\Dumper;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\SpyDumper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
});

describe('trace()', function () {
    it('records the chain-level entries and stores lastTrace()', function () {
        $pending = ClosureAction::make()->trace();

        expect($pending->lastTrace())->toBeNull();

        $pending->handle(fn (): string => 'done');

        $trace = $pending->lastTrace();

        expect($trace)->toBeInstanceOf(Trace::class);
        expect($trace->has(TraceEvent::Started))->toBeTrue();
        expect($trace->has(TraceEvent::Completed))->toBeTrue();
        expect($trace->has(TraceEvent::Failed))->toBeFalse();
        expect($trace->durationMs())->toBeGreaterThan(0.0);
    });

    it('gives the callback the trace after a successful run', function () {
        $seen = null;

        ClosureAction::make()
            ->trace(function (Trace $trace) use (&$seen): void {
                $seen = $trace;
            })
            ->handle(fn (): string => 'x');

        expect($seen)->toBeInstanceOf(Trace::class);
        expect($seen->has(TraceEvent::Completed))->toBeTrue();
    });

    it('gives the callback the trace when the chain throws, then rethrows', function () {
        $seen = null;

        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        // The callback is built in the test scope: an arrow function would
        // capture $seen by value and swallow the by-ref write.
        $pending = ClosureAction::make()
            ->trace(function (Trace $trace) use (&$seen): void {
                $seen = $trace;
            });

        expect(fn () => $pending->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        expect($seen)->toBeInstanceOf(Trace::class);
        expect($seen->has(TraceEvent::Failed))->toBeTrue();
        expect($seen->first(TraceEvent::Failed)?->context['exception'])->toBe(RuntimeException::class);
    });

    it('replaces lastTrace() on the next run', function () {
        $pending = ClosureAction::make()->trace();

        $pending->handle(fn (): string => 'first');
        $first = $pending->lastTrace();

        $pending->handle(fn (): string => 'second');

        expect($pending->lastTrace())->not->toBe($first);
    });

    it('leaves lastTrace() null when tracing is off', function () {
        $pending = ClosureAction::make()->observed();

        $pending->handle(fn (): string => 'x');

        expect($pending->lastTrace())->toBeNull();
    });

    it('attaches the trace to the lifecycle events when enabled, null otherwise', function () {
        $completed = [];

        Event::listen(ActionCompleted::class, function (ActionCompleted $event) use (&$completed): void {
            $completed[] = $event;
        });

        ClosureAction::make()->trace()->handle(fn (): string => 'x');
        ClosureAction::make()->observed()->handle(fn (): string => 'x');

        expect($completed)->toHaveCount(2);
        expect($completed[0]->trace)->toBeInstanceOf(Trace::class);
        expect($completed[1]->trace)->toBeNull();
    });
});

describe('middleware decisions', function () {
    it('records failed attempts and sleeps for retry()', function () {
        $attempts = 0;

        $flaky = function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new RuntimeException('flaky');
            }

            return 'done';
        };

        $pending = ClosureAction::make()->retry(times: 3, backoff: 100)->trace();
        $pending->handle($flaky);

        $trace = $pending->lastTrace();

        expect($trace->count(TraceEvent::RetryAttempt))->toBe(2);
        expect($trace->first(TraceEvent::RetryAttempt)?->context)
            ->toBe(['attempt' => 1, 'exception' => RuntimeException::class]);
        expect($trace->count(TraceEvent::RetrySlept))->toBe(2);
        expect($trace->first(TraceEvent::RetrySlept)?->context['milliseconds'])->toBe(100);
    });

    it('records stored and hit idempotency decisions', function () {
        $first = ClosureAction::make()->idempotent('trace-idem')->trace();
        $first->handle(fn (): string => 'v');

        expect($first->lastTrace()?->has(TraceEvent::IdempotencyStored))->toBeTrue();
        expect($first->lastTrace()?->has(TraceEvent::IdempotencyHit))->toBeFalse();

        $second = ClosureAction::make()->idempotent('trace-idem')->trace();
        $second->handle(fn (): string => 'v');

        expect($second->lastTrace()?->has(TraceEvent::IdempotencyHit))->toBeTrue();
        expect($second->lastTrace()?->has(TraceEvent::IdempotencyStored))->toBeFalse();
        expect($second->lastTrace()?->has(TraceEvent::Completed))->toBeTrue();
    });

    it('records a consumed fallback on a rescued run', function () {
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        $pending = ClosureAction::make()
            ->fallback(fn (Throwable $e): string => 'rescued')
            ->trace();

        $result = $pending->handle($throwing);

        expect($result)->toBe('rescued');
        expect($pending->lastTrace()?->first(TraceEvent::FallbackUsed)?->context['exception'])
            ->toBe(RuntimeException::class);
        expect($pending->lastTrace()?->has(TraceEvent::Completed))->toBeTrue();
    });

    it('records a memoize hit on the second run only', function () {
        $pending = ClosureAction::make()->memoize('trace-memo')->trace();

        $pending->handle(fn (): string => 'x');
        expect($pending->lastTrace()?->has(TraceEvent::MemoizeHit))->toBeFalse();

        $pending->handle(fn (): string => 'x');
        expect($pending->lastTrace()?->has(TraceEvent::MemoizeHit))->toBeTrue();
    });

    it('records the acquired overlap lock', function () {
        $pending = ClosureAction::make()->withoutOverlapping('trace-lock')->trace();

        $pending->handle(fn (): string => 'x');

        expect($pending->lastTrace()?->first(TraceEvent::LockAcquired)?->context['key'])->toBe('trace-lock');
    });

    it('records a throttled rejection', function () {
        ClosureAction::make()->throttle('trace-throttle', allow: 1)->handle(fn (): string => 'first');

        $pending = ClosureAction::make()->throttle('trace-throttle', allow: 1)->trace();

        expect(fn () => $pending->handle(fn (): string => 'second'))
            ->toThrow(ThrottledException::class);

        expect($pending->lastTrace()?->has(TraceEvent::Throttled))->toBeTrue();
        expect($pending->lastTrace()?->has(TraceEvent::Failed))->toBeTrue();
    });

    it('records the breaker tripping and later open rejections', function () {
        $down = function (): void {
            throw new RuntimeException('down');
        };

        $tripping = ClosureAction::make()->circuitBreaker('trace-breaker', threshold: 1)->trace();

        expect(fn () => $tripping->handle($down))->toThrow(RuntimeException::class, 'down');
        expect($tripping->lastTrace()?->first(TraceEvent::CircuitTripped)?->context['failures'])->toBe(1);

        $rejected = ClosureAction::make()->circuitBreaker('trace-breaker', threshold: 1)->trace();

        expect(fn () => $rejected->handle(fn (): string => 'x'))->toThrow(CircuitOpenException::class);
        expect($rejected->lastTrace()?->has(TraceEvent::CircuitOpenRejected))->toBeTrue();
    });

    it('records transaction commits and concurrency retries', function () {
        $runs = 0;

        $flaky = function () use (&$runs) {
            $runs++;

            if ($runs === 1) {
                // Laravel's transaction() recognises this as a concurrency
                // error and re-runs the transaction closure.
                throw new Exception('deadlock detected');
            }

            return 'done';
        };

        $pending = ClosureAction::make()->transactional(attempts: 2)->trace();
        $pending->handle($flaky);

        expect($pending->lastTrace()?->first(TraceEvent::TransactionRetried)?->context['attempt'])->toBe(2);
        expect($pending->lastTrace()?->has(TraceEvent::TransactionCommitted))->toBeTrue();
    });
});

describe('dumpTrace() / ddTrace()', function () {
    beforeEach(function () {
        $this->dumper = new SpyDumper;
        app()->instance(Dumper::class, $this->dumper);
    });

    it('prints the summary after the run', function () {
        ClosureAction::make()->dumpTrace()->handle(fn (): string => 'x');

        expect($this->dumper->dumped)->toHaveCount(1);
        expect($this->dumper->dumped[0])->toContain('started')->toContain('completed');
    });

    it('prints the summary when the run throws', function () {
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::make()->dumpTrace()->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        expect($this->dumper->dumped)->toHaveCount(1);
        expect($this->dumper->dumped[0])->toContain('failed (RuntimeException)');
    });

    it('ddTrace() dumps and terminates', function () {
        expect(fn () => ClosureAction::make()->ddTrace()->handle(fn (): string => 'x'))
            ->toThrow(RuntimeException::class, 'dd() would have terminated');

        expect($this->dumper->dumped)->toHaveCount(1);
    });
});
