<?php

use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
});

describe('fallback()', function () {
    it('returns the fallback value when the action throws', function () {
        $received = null;

        $fallback = function (Throwable $e) use (&$received) {
            $received = $e;

            return 'fallback-value';
        };

        $result = ClosureAction::make()->fallback($fallback)->handle(function () {
            throw new RuntimeException('boom');
        });

        expect($result)->toBe('fallback-value');
        expect($received)->toBeInstanceOf(RuntimeException::class);
        expect($received->getMessage())->toBe('boom');
    });

    it('is not invoked when the action succeeds', function () {
        $called = false;

        $fallback = function (Throwable $e) use (&$called) {
            $called = true;

            return 'fallback-value';
        };

        $result = ClosureAction::make()->fallback($fallback)->handle(fn () => 'real');

        expect($result)->toBe('real');
        expect($called)->toBeFalse();
    });

    it('propagates an exception rethrown from inside the fallback', function () {
        $wrapper = ClosureAction::make()->fallback(function (Throwable $e) {
            throw new LogicException('declined: '.$e->getMessage());
        });

        expect(fn () => $wrapper->handle(function () {
            throw new RuntimeException('boom');
        }))->toThrow(LogicException::class, 'declined: boom');
    });

    it('returns the chainable wrapper', function () {
        expect(ClosureAction::make()->fallback(fn (Throwable $e) => null))
            ->toBeInstanceOf(PendingAction::class);
    });

    it('never caches the fallback value as an idempotent result', function () {
        $count = 0;

        $action = ClosureAction::make();

        $throwing = function () use (&$count) {
            $count++;

            throw new RuntimeException('down');
        };

        // The action fails, the fallback answers — but the key must stay free.
        $degraded = $action->idempotent('fallback-key')->fallback(fn (Throwable $e) => 'degraded')
            ->handle($throwing);

        expect($degraded)->toBe('degraded');
        expect($count)->toBe(1);

        // Next run executes again and its real result is the one cached.
        $real = $action->fallback(fn (Throwable $e) => 'degraded')->idempotent('fallback-key')
            ->handle(function () use (&$count) {
                $count++;

                return 'real';
            });

        expect($real)->toBe('real');
        expect($count)->toBe(2);

        $cached = $action->idempotent('fallback-key')->handle($throwing);

        expect($cached)->toBe('real');
        expect($count)->toBe(2);
    });

    it('engages only after retry() has exhausted its attempts', function () {
        $attempts = 0;

        $throwing = function () use (&$attempts) {
            $attempts++;

            throw new RuntimeException('flaky');
        };

        $result = ClosureAction::make()
            ->fallback(fn (Throwable $e) => 'gave-up: '.$e->getMessage())
            ->retry(times: 3)
            ->handle($throwing);

        expect($result)->toBe('gave-up: flaky');
        expect($attempts)->toBe(3);
    });
});
