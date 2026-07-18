<?php

use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

describe('Testable chain safety', function () {
    it('keeps the chain through on(): idempotency configured before still applies', function () {
        $received = [];

        $testable = ClosureAction::test()
            ->idempotent('chain-on-key')
            ->on('test.event.a', function ($data) use (&$received) {
                $received[] = $data;
            });

        expect($testable)->toBeInstanceOf(Testable::class);

        $testable->handle(function (ClosureAction $action) {
            $action->event('test.event.a', 'payload');

            return 'value';
        });

        expect($received)->toBe(['payload']);
        expect($testable->wasExecuted())->toBeTrue();
    });

    it('rejects execution wrappers instead of silently derailing the chain', function (string $method, array $arguments) {
        expect(fn () => ClosureAction::test()->{$method}(...$arguments))
            ->toThrow(LogicException::class, $method.'()');
    })->with([
        'retry' => ['retry', [2]],
        'fallback' => ['fallback', [fn (Throwable $e) => null]],
        'circuitBreaker' => ['circuitBreaker', ['key']],
        'throttle' => ['throttle', ['key']],
        'withoutOverlapping' => ['withoutOverlapping', ['key']],
        'transactional' => ['transactional', []],
        'memoize' => ['memoize', ['key']],
        'once' => ['once', ['key']],
        'observed' => ['observed', []],
        'trace' => ['trace', []],
        'dumpTrace' => ['dumpTrace', []],
        'ddTrace' => ['ddTrace', []],
        'defer' => ['defer', [fn (ClosureAction $action) => null]],
    ]);
});
