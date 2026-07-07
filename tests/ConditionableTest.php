<?php

use Iak\Action\PendingAction;
use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

describe('when() / unless()', function () {
    it('applies the callback on the action when the condition is true', function () {
        $attempts = 0;

        $flaky = function () use (&$attempts) {
            $attempts++;

            if ($attempts < 2) {
                throw new RuntimeException('flaky');
            }

            return 'done';
        };

        $result = ClosureAction::make()
            ->when(true, fn (ClosureAction $action): PendingAction => $action->retry(times: 2))
            ->handle($flaky);

        expect($result)->toBe('done');
        expect($attempts)->toBe(2);
    });

    it('skips the callback when the condition is false', function () {
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::make()
            ->when(false, fn (ClosureAction $action): PendingAction => $action->retry(times: 3))
            ->handle($throwing))->toThrow(RuntimeException::class, 'boom');
    });

    it('chains conditionally on the pending wrapper', function () {
        $attempts = 0;

        $flaky = function () use (&$attempts) {
            $attempts++;

            if ($attempts < 2) {
                throw new RuntimeException('flaky');
            }

            return 'done';
        };

        $result = ClosureAction::make()->observed()
            ->unless(false, fn (PendingAction $pending): PendingAction => $pending->retry(times: 2))
            ->handle($flaky);

        expect($result)->toBe('done');
    });

    it('runs the default callback when the condition is false', function () {
        $attempts = 0;

        $flaky = function () use (&$attempts) {
            $attempts++;

            if ($attempts < 2) {
                throw new RuntimeException('flaky');
            }

            return 'done';
        };

        $result = ClosureAction::make()->observed()
            ->when(
                false,
                fn (PendingAction $pending): PendingAction => $pending->throttle('conditional-throttle', allow: 1),
                fn (PendingAction $pending): PendingAction => $pending->retry(times: 2),
            )
            ->handle($flaky);

        expect($result)->toBe('done');
    });

    it('chains conditionally on Testable', function () {
        $testable = ClosureAction::test()
            ->when(true, fn (Testable $testable): Testable => $testable->idempotent('conditional-key'));

        $testable->handle(fn (): string => 'v');

        expect($testable->wasExecuted())->toBeTrue();
    });
});
