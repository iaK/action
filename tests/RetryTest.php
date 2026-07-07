<?php

use Iak\Action\Exceptions\NonRetryable;
use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
});

describe('retry()', function () {
    it('retries a failing action until it succeeds', function () {
        $attempts = 0;

        $result = ClosureAction::make()->retry(times: 3)->handle(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new RuntimeException('flaky');
            }

            return 'success';
        });

        expect($result)->toBe('success');
        expect($attempts)->toBe(3);
    });

    it('gives up after the configured total attempts and rethrows', function () {
        $attempts = 0;

        // Defined in test scope: an arrow function would sever the by-ref
        // capture (fn() captures by value, including nested use(&...) vars).
        $throwing = function () use (&$attempts) {
            $attempts++;

            throw new RuntimeException('always failing');
        };

        expect(fn () => ClosureAction::make()->retry(times: 3)->handle($throwing))
            ->toThrow(RuntimeException::class, 'always failing');

        expect($attempts)->toBe(3);
    });

    it('does not retry when the action succeeds on the first attempt', function () {
        $attempts = 0;

        $result = ClosureAction::make()->retry(times: 3)->handle(function () use (&$attempts) {
            $attempts++;

            return 'first';
        });

        expect($result)->toBe('first');
        expect($attempts)->toBe(1);
        Sleep::assertNeverSlept();
    });

    it('rejects fewer than one attempt', function () {
        expect(fn () => ClosureAction::make()->retry(times: 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('sleeps the fixed backoff between attempts but not after the last one', function () {
        $attempts = 0;

        expect(fn () => ClosureAction::make()->retry(times: 3, backoff: 100)->handle(function () use (&$attempts) {
            $attempts++;

            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class);

        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
            Sleep::for(100)->milliseconds(),
        ]);
    });

    it('follows a per-attempt backoff schedule and repeats the last entry', function () {
        expect(fn () => ClosureAction::make()->retry(times: 4, backoff: [100, 500])->handle(function () {
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class);

        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
            Sleep::for(500)->milliseconds(),
            Sleep::for(500)->milliseconds(),
        ]);
    });

    it('accepts a backoff closure receiving the attempt number and the exception', function () {
        $seen = [];

        $backoff = function (int $attempt, Throwable $e) use (&$seen) {
            $seen[] = [$attempt, $e->getMessage()];

            return $attempt * 10;
        };

        expect(fn () => ClosureAction::make()
            ->retry(times: 3, backoff: $backoff)
            ->handle(function () {
                throw new RuntimeException('boom');
            }))->toThrow(RuntimeException::class);

        expect($seen)->toBe([[1, 'boom'], [2, 'boom']]);
        Sleep::assertSequence([
            Sleep::for(10)->milliseconds(),
            Sleep::for(20)->milliseconds(),
        ]);
    });

    it('stops retrying when the when filter declines the exception', function () {
        $attempts = 0;

        $throwing = function () use (&$attempts) {
            $attempts++;

            throw new LogicException('not retryable');
        };

        expect(fn () => ClosureAction::make()
            ->retry(times: 3, when: fn (Throwable $e) => $e instanceof RuntimeException)
            ->handle($throwing))->toThrow(LogicException::class);

        expect($attempts)->toBe(1);
    });

    it('does not retry NonRetryable exceptions by default', function () {
        $attempts = 0;

        $exception = new class('permanent') extends RuntimeException implements NonRetryable {};

        $throwing = function () use (&$attempts, $exception) {
            $attempts++;

            throw $exception;
        };

        expect(fn () => ClosureAction::make()->retry(times: 3)->handle($throwing))
            ->toThrow(RuntimeException::class, 'permanent');

        expect($attempts)->toBe(1);
    });

    it('lets an explicit when filter overrule the NonRetryable default', function () {
        $attempts = 0;

        $exception = new class('permanent') extends RuntimeException implements NonRetryable {};

        $throwing = function () use (&$attempts, $exception) {
            $attempts++;

            throw $exception;
        };

        expect(fn () => ClosureAction::make()
            ->retry(times: 2, when: fn (Throwable $e) => true)
            ->handle($throwing))->toThrow(RuntimeException::class);

        expect($attempts)->toBe(2);
    });

    it('returns the chainable wrapper', function () {
        expect(ClosureAction::make()->retry())->toBeInstanceOf(PendingAction::class);
    });

    it('chains with idempotent() in either order: failures leave the key free, success caches once', function () {
        $count = 0;

        $action = ClosureAction::make();

        // Every attempt fails: the key must stay unconsumed.
        $throwing = function () use (&$count) {
            $count++;

            throw new RuntimeException('boom');
        };

        expect(fn () => $action->retry(times: 2)->idempotent('retry-key')->handle($throwing))
            ->toThrow(RuntimeException::class);

        expect($count)->toBe(2);

        // Succeeds on the second attempt of this run: cached from now on.
        $result = $action->idempotent('retry-key')->retry(times: 3)->handle(function () use (&$count) {
            $count++;

            if ($count < 4) {
                throw new RuntimeException('flaky');
            }

            return 'ok';
        });

        expect($result)->toBe('ok');
        expect($count)->toBe(4);

        // Served from cache: the closure never runs again.
        $cached = $action->idempotent('retry-key')->retry(times: 3)->handle(function () use (&$count) {
            $count++;

            return 'never';
        });

        expect($cached)->toBe('ok');
        expect($count)->toBe(4);
    });
});
