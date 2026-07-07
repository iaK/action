<?php

use Iak\Action\Action;
use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\CountingAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;

beforeEach(function () {
    CountingAction::$runs = 0;
});

describe('memoize()', function () {
    it('executes once per argument list within the process', function () {
        $first = CountingAction::make()->memoize()->handle(21);
        $second = CountingAction::make()->memoize()->handle(21);

        expect($first)->toBe(42);
        expect($second)->toBe(42);
        expect(CountingAction::$runs)->toBe(1);
    });

    it('keys by arguments, so different arguments execute separately', function () {
        expect(CountingAction::make()->memoize()->handle(1))->toBe(2);
        expect(CountingAction::make()->memoize()->handle(2))->toBe(4);
        expect(CountingAction::$runs)->toBe(2);
    });

    it('memoizes falsy results', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return false;
        };

        expect(ClosureAction::make()->memoize('falsy')->handle($closure))->toBeFalse();
        expect(ClosureAction::make()->memoize('falsy')->handle($closure))->toBeFalse();
        expect($count)->toBe(1);
    });

    it('scopes entries per action class', function () {
        $countA = 0;
        $countB = 0;

        $a = ClosureAction::make()->memoize('shared')->handle(function () use (&$countA) {
            $countA++;

            return 'A';
        });

        $b = OtherClosureAction::make()->memoize('shared')->handle(function () use (&$countB) {
            $countB++;

            return 'B';
        });

        expect($a)->toBe('A');
        expect($b)->toBe('B');
        expect($countA)->toBe(1);
        expect($countB)->toBe(1);
    });

    it('does not memoize a failed run', function () {
        $count = 0;

        $throwing = function () use (&$count) {
            $count++;

            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::make()->memoize('failing')->handle($throwing))
            ->toThrow(RuntimeException::class);

        $result = ClosureAction::make()->memoize('failing')->handle(function () use (&$count) {
            $count++;

            return 'ok';
        });

        expect($result)->toBe('ok');
        expect($count)->toBe(2);
    });

    it('requires an explicit key when the arguments cannot be serialized', function () {
        expect(fn () => ClosureAction::make()->memoize()->handle(fn () => 'value'))
            ->toThrow(InvalidArgumentException::class, 'explicit key');
    });

    it('requires an explicit key with run()', function () {
        expect(fn () => CountingAction::make()->memoize()->run(fn (CountingAction $a) => $a->handle(1)))
            ->toThrow(InvalidArgumentException::class, 'run()');
    });

    it('memoizes through run() with an explicit key', function () {
        $first = CountingAction::make()->memoize('via-run')->run(fn (CountingAction $a) => $a->handle(5));
        $second = CountingAction::make()->memoize('via-run')->run(fn (CountingAction $a) => $a->handle(5));

        expect($first)->toBe(10);
        expect($second)->toBe(10);
        expect(CountingAction::$runs)->toBe(1);
    });

    it('is cleared by Action::flushMemoized()', function () {
        CountingAction::make()->memoize()->handle(3);

        Action::flushMemoized();

        CountingAction::make()->memoize()->handle(3);

        expect(CountingAction::$runs)->toBe(2);
    });

    it('returns the chainable wrapper', function () {
        expect(CountingAction::make()->memoize())->toBeInstanceOf(PendingAction::class);
    });
});
