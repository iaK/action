<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    Cache::flush();
});

describe('Testable idempotent()', function () {
    it('executes once per key through test() and reports wasExecuted()', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $first = ClosureAction::test();

        expect($first->idempotent('testable-key')->handle($closure))->toBe('value');
        expect($first->wasExecuted())->toBeTrue();

        $second = ClosureAction::test();

        expect($second->idempotent('testable-key')->handle($closure))->toBe('value');
        expect($second->wasExecuted())->toBeFalse();
        expect($count)->toBe(1);
    });

    it('chains with instruments and only instruments the executing run', function () {
        $profiled = 0;

        ClosureAction::test()
            ->profile(function ($profiles) use (&$profiled) {
                $profiled++;
            })
            ->idempotent('instrumented-key')
            ->handle(fn () => 'value');

        expect($profiled)->toBe(1);

        // Served from cache: nothing executes, so nothing is instrumented and
        // no inspection callbacks fire. Chaining order does not matter.
        $cachedRun = ClosureAction::test()
            ->idempotent('instrumented-key')
            ->profile(function ($profiles) use (&$profiled) {
                $profiled++;
            });

        expect($cachedRun->handle(fn () => 'value'))->toBe('value');
        expect($profiled)->toBe(1);
        expect($cachedRun->wasExecuted())->toBeFalse();
    });

    it('shares keys with the production idempotent() wrapper', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        ClosureAction::make()->idempotent('shared-key')->handle($closure);

        $testable = ClosureAction::test()->idempotent('shared-key');

        expect($testable->handle($closure))->toBe('value');
        expect($testable->wasExecuted())->toBeFalse();
        expect($count)->toBe(1);
    });

    it('returns null from wasExecuted() when idempotency is not configured', function () {
        $testable = ClosureAction::test();

        $testable->handle(fn () => 'value');

        expect($testable->wasExecuted())->toBeNull();
    });
});

describe('assertExecuted() / assertSkipped()', function () {
    it('assertExecuted() passes after an executing run and fails after a cached one', function () {
        $first = ClosureAction::test()->idempotent('assert-key');
        $first->handle(fn (): string => 'v');

        expect($first->assertExecuted())->toBe($first);

        $second = ClosureAction::test()->idempotent('assert-key');
        $second->handle(fn (): string => 'v');

        expect(fn () => $second->assertExecuted())
            ->toThrow(AssertionFailedError::class, 'served from the idempotency cache');
    });

    it('assertSkipped() passes after a cached run and fails after an executing one', function () {
        $first = ClosureAction::test()->idempotent('skip-key');
        $first->handle(fn (): string => 'v');

        expect(fn () => $first->assertSkipped())
            ->toThrow(AssertionFailedError::class, 'but the action executed');

        $second = ClosureAction::test()->idempotent('skip-key');
        $second->handle(fn (): string => 'v');

        expect($second->assertSkipped())->toBe($second);
    });

    it('fails with a clear message when idempotent() was not configured or handle() has not run', function () {
        expect(fn () => ClosureAction::test()->assertExecuted())
            ->toThrow(AssertionFailedError::class, 'idempotent() was not configured or handle() has not run');

        expect(fn () => ClosureAction::test()->idempotent('never-ran')->assertSkipped())
            ->toThrow(AssertionFailedError::class, 'idempotent() was not configured or handle() has not run');
    });
});
