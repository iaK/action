<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Collection;

describe('Typed handle()', function () {
    it('runs the action through then() with instruments firing', function () {
        $profiles = null;

        $result = ClosureAction::test()
            ->profile(function (Collection $received) use (&$profiles) {
                $profiles = $received;
            })
            ->then(fn (ClosureAction $action) => $action->handle(fn () => 'typed'));

        expect($result)->toBe('typed')
            ->and($profiles)->toHaveCount(1);
    });

    it('still intercepts handle() through __call', function () {
        expect(ClosureAction::test()->handle(fn () => 'via-call'))->toBe('via-call');
    });

    it('forwards non-handle calls to the wrapped action', function () {
        expect(ClosureAction::test()->getAllowedEvents())
            ->toBe(['test.event.a', 'test.event.b']);
    });

    it('chains then() with idempotent()', function () {
        $testable = ClosureAction::test()->idempotent('typed-run-key');
        $first = $testable->then(fn (ClosureAction $action) => $action->handle(fn () => 'first'));

        expect($first)->toBe('first')
            ->and($testable->wasExecuted())->toBeTrue();

        $second = ClosureAction::test()
            ->idempotent('typed-run-key')
            ->then(fn (ClosureAction $action) => $action->handle(fn () => 'second'));

        expect($second)->toBe('first');
    });
});
