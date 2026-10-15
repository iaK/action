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
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        $result = ClosureAction::make()
            ->when(true, fn (ClosureAction $action): PendingAction => $action->fallback(fn (Throwable $e): string => 'rescued'))
            ->handle($throwing);

        expect($result)->toBe('rescued');
    });

    it('skips the callback when the condition is false', function () {
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::make()
            ->when(false, fn (ClosureAction $action): PendingAction => $action->fallback(fn (Throwable $e): string => 'rescued'))
            ->handle($throwing))->toThrow(RuntimeException::class, 'boom');
    });

    it('chains conditionally on the pending wrapper', function () {
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        $result = ClosureAction::make()->observed()
            ->unless(false, fn (PendingAction $pending): PendingAction => $pending->fallback(fn (Throwable $e): string => 'rescued'))
            ->handle($throwing);

        expect($result)->toBe('rescued');
    });

    it('runs the default callback when the condition is false', function () {
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        $result = ClosureAction::make()->observed()
            ->when(
                false,
                fn (PendingAction $pending): PendingAction => $pending->memoize('conditional-memo'),
                fn (PendingAction $pending): PendingAction => $pending->fallback(fn (Throwable $e): string => 'rescued'),
            )
            ->handle($throwing);

        expect($result)->toBe('rescued');
    });

    it('chains conditionally on Testable', function () {
        $testable = ClosureAction::test()
            ->when(true, fn (Testable $testable): Testable => $testable->idempotent('conditional-key'));

        $testable->handle(fn (): string => 'v');

        expect($testable->wasExecuted())->toBeTrue();
    });
});
