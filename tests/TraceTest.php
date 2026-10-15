<?php

use Iak\Action\Execution\Trace;
use Iak\Action\Execution\TraceEntry;
use Iak\Action\Execution\TraceEvent;
use Iak\Action\Execution\TraceRecorder;

describe('TraceRecorder', function () {
    it('records entries with monotonic offsets and finishes into a Trace', function () {
        $recorder = new TraceRecorder;
        $recorder->record('action', TraceEvent::Started);
        $recorder->record('transactional', TraceEvent::TransactionRetried, ['attempt' => 1, 'exception' => RuntimeException::class]);

        $trace = $recorder->finish();

        expect($trace)->toBeInstanceOf(Trace::class);
        expect($trace->entries())->toHaveCount(2);
        expect($trace->entries()[0]->event)->toBe(TraceEvent::Started);
        expect($trace->entries()[0]->slot)->toBe('action');
        expect($trace->entries()[1]->atMs)->toBeGreaterThanOrEqual($trace->entries()[0]->atMs);
        expect($trace->entries()[1]->context)->toBe(['attempt' => 1, 'exception' => RuntimeException::class]);
    });
});

describe('Trace', function () {
    it('answers has(), count(), first() and durationMs()', function () {
        $trace = new Trace([
            new TraceEntry('action', TraceEvent::Started, 0.0),
            new TraceEntry('transactional', TraceEvent::TransactionRetried, 1.0, ['attempt' => 1, 'exception' => 'E']),
            new TraceEntry('transactional', TraceEvent::TransactionRetried, 2.0, ['attempt' => 2, 'exception' => 'E']),
            new TraceEntry('action', TraceEvent::Completed, 3.0, ['duration_ms' => 3.0]),
        ]);

        expect($trace->has(TraceEvent::TransactionRetried))->toBeTrue();
        expect($trace->has(TraceEvent::Throttled))->toBeFalse();
        expect($trace->count(TraceEvent::TransactionRetried))->toBe(2);
        expect($trace->first(TraceEvent::TransactionRetried)?->context['attempt'])->toBe(1);
        expect($trace->durationMs())->toBe(3.0);
        expect((new Trace([]))->durationMs())->toBe(0.0);
    });

    it('renders a one-line-per-entry summary and stringifies to it', function () {
        $trace = new Trace([
            new TraceEntry('action', TraceEvent::Started, 0.0),
            new TraceEntry('throttle', TraceEvent::Throttled, 1.2, ['available_in' => 30]),
            new TraceEntry('action', TraceEvent::Completed, 2.4, ['duration_ms' => 2.4]),
        ]);

        $summary = $trace->summary();

        expect($summary)
            ->toContain('+0.0ms')
            ->toContain('started')
            ->toContain('throttle: budget exhausted (available in 30s)')
            ->toContain('completed (2.4ms)');
        expect((string) $trace)->toBe($summary);
    });
});
