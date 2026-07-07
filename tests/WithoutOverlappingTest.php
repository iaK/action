<?php

use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ArrayNoLockStore;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

describe('withoutOverlapping()', function () {
    it('runs normally when nothing holds the lock and releases it afterwards', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'done';
        };

        $action = ClosureAction::make();

        // Two sequential runs both execute: the lock is released in between.
        expect($action->withoutOverlapping('job')->handle($closure))->toBe('done');
        expect($action->withoutOverlapping('job')->handle($closure))->toBe('done');
        expect($count)->toBe(2);
    });

    it('throws immediately when the lock is held and wait is zero', function () {
        // A concurrent run elsewhere holds the lock.
        Cache::lock('action.overlap:job', 10)->get();

        $ran = false;

        expect(fn () => ClosureAction::make()->withoutOverlapping('job')->handle(function () use (&$ran) {
            $ran = true;
        }))->toThrow(LockTimeoutException::class);

        expect($ran)->toBeFalse();
    });

    it('does not release a lock it failed to acquire', function () {
        Cache::lock('action.overlap:job', 10)->get();

        try {
            ClosureAction::make()->withoutOverlapping('job')->handle(fn () => null);
        } catch (LockTimeoutException) {
        }

        // The foreign lock must still be held after our failed attempt.
        expect(Cache::lock('action.overlap:job', 10)->get())->toBeFalse();
    });

    it('acquires through blocking when waiting is allowed and the lock is free', function () {
        $result = ClosureAction::make()->withoutOverlapping('job', wait: 5)->handle(fn () => 'done');

        expect($result)->toBe('done');
    });

    it('scopes the lock to the action class by default', function () {
        Cache::lock('action.overlap:'.ClosureAction::class, 10)->get();

        expect(fn () => ClosureAction::make()->withoutOverlapping()->handle(fn () => null))
            ->toThrow(LockTimeoutException::class);
    });

    it('releases the lock when the action throws', function () {
        $throwing = function () {
            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::make()->withoutOverlapping('job')->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        // The failed run released the lock, so the next one executes.
        $result = ClosureAction::make()->withoutOverlapping('job')->handle(fn () => 'recovered');

        expect($result)->toBe('recovered');
    });

    it('rejects a cache store without lock support with a clear message', function () {
        config()->set('cache.stores.nolock', ['driver' => 'nolock']);
        Cache::extend('nolock', fn () => new Repository(new ArrayNoLockStore));

        expect(fn () => ClosureAction::make()->withoutOverlapping('job', store: 'nolock')->handle(fn () => null))
            ->toThrow(RuntimeException::class, 'lock');
    });

    it('rejects a negative wait and a staleAfter below one', function () {
        expect(fn () => ClosureAction::make()->withoutOverlapping('job', wait: -1))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ClosureAction::make()->withoutOverlapping('job', staleAfter: 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('returns the chainable wrapper', function () {
        expect(ClosureAction::make()->withoutOverlapping('job'))->toBeInstanceOf(PendingAction::class);
    });
});
