<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

beforeEach(function () {
    Cache::flush();
});

describe('automatic log context', function () {
    it('exposes the running action class under the action key', function () {
        $seen = ClosureAction::make()->observed()->handle(fn (): mixed => Context::get('action'));

        expect($seen)->toBe(ClosureAction::class);
        expect(Context::has('action'))->toBeFalse();
    });

    it('leaves the context untouched for wrapper features without observed()', function () {
        $seen = ClosureAction::make()->retry(times: 2)->handle(fn (): mixed => Context::get('action'));

        expect($seen)->toBeNull();
        expect(Context::has('action'))->toBeFalse();
    });

    it('covers the Testable execute path', function () {
        $seen = ClosureAction::test()->handle(fn (): mixed => Context::get('action'));

        expect($seen)->toBe(ClosureAction::class);
        expect(Context::has('action'))->toBeFalse();
    });

    it('restores the key after a throwing run', function () {
        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::make()->observed()->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        expect(Context::has('action'))->toBeFalse();
    });

    it('attributes nested actions to the innermost one and restores the outer', function () {
        $seen = [];

        ClosureAction::make()->observed()->handle(function () use (&$seen): void {
            $seen['outer-before'] = Context::get('action');

            OtherClosureAction::make()->observed()->handle(function () use (&$seen): void {
                $seen['inner'] = Context::get('action');
            });

            $seen['outer-after'] = Context::get('action');
        });

        expect($seen)->toBe([
            'outer-before' => ClosureAction::class,
            'inner' => OtherClosureAction::class,
            'outer-after' => ClosureAction::class,
        ]);
    });

    it('restores a pre-existing action context value', function () {
        Context::add('action', 'user-value');

        ClosureAction::make()->observed()->handle(fn (): string => 'x');

        expect(Context::get('action'))->toBe('user-value');

        Context::forget('action');
    });
});
