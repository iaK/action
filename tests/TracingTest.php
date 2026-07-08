<?php

use Iak\Action\Events\ActionCompleted;
use Iak\Action\Execution\Trace;
use Iak\Action\Execution\TraceEvent;
use Iak\Action\Tests\TestClasses\ClosureAction;
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
