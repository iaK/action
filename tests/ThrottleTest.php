<?php

use Iak\Action\Exceptions\ThrottledException;
use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    app(RateLimiter::class)->clear('action.throttle:limited');
    Sleep::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('throttle()', function () {
    it('allows calls under the limit and throws once it is exceeded', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'ok';
        };

        $action = ClosureAction::make();

        expect($action->throttle('limited', allow: 2, every: 60)->handle($closure))->toBe('ok');
        expect($action->throttle('limited', allow: 2, every: 60)->handle($closure))->toBe('ok');

        expect(fn () => $action->throttle('limited', allow: 2, every: 60)->handle($closure))
            ->toThrow(ThrottledException::class);

        expect($count)->toBe(2);
    });

    it('reports the key and availableIn() on the exception', function () {
        $action = ClosureAction::make();

        $action->throttle('limited', allow: 1, every: 60)->handle(fn () => 'ok');

        try {
            $action->throttle('limited', allow: 1, every: 60)->handle(fn () => 'ok');
            $this->fail('Expected ThrottledException');
        } catch (ThrottledException $e) {
            expect($e->key())->toBe('limited');
            expect($e->availableIn())->toBeGreaterThan(0);
            expect($e->availableIn())->toBeLessThanOrEqual(60);
        }
    });

    it('scopes the limiter to the action class by default', function () {
        ClosureAction::make()->throttle(allow: 1)->handle(fn () => 'ok');

        expect(fn () => ClosureAction::make()->throttle(allow: 1)->handle(fn () => 'ok'))
            ->toThrow(ThrottledException::class);

        // A different action class has its own budget.
        $result = OtherClosureAction::make()->throttle(allow: 1)->handle(fn () => 'ok');

        expect($result)->toBe('ok');
    });

    it('consumes budget per attempt when nested inside retry()', function () {
        $attempts = 0;

        $throwing = function () use (&$attempts) {
            $attempts++;

            throw new RuntimeException('flaky');
        };

        // Three retry attempts against a budget of two: the first two execute
        // (and fail), the third is throttled before executing.
        expect(fn () => ClosureAction::make()
            ->retry(times: 3)
            ->throttle('limited', allow: 2, every: 60)
            ->handle($throwing))->toThrow(ThrottledException::class);

        expect($attempts)->toBe(2);
    });

    it('frees the budget once the decay window has passed', function () {
        Carbon::setTestNow(now());

        $action = ClosureAction::make();

        $action->throttle('limited', allow: 1, every: 60)->handle(fn () => 'ok');

        expect(fn () => $action->throttle('limited', allow: 1, every: 60)->handle(fn () => 'ok'))
            ->toThrow(ThrottledException::class);

        Carbon::setTestNow(now()->addSeconds(61));

        $result = $action->throttle('limited', allow: 1, every: 60)->handle(fn () => 'ok');

        expect($result)->toBe('ok');
    });

    it('rejects allow and every below one', function () {
        expect(fn () => ClosureAction::make()->throttle('limited', allow: 0))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ClosureAction::make()->throttle('limited', every: 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('returns the chainable wrapper', function () {
        expect(ClosureAction::make()->throttle('limited'))->toBeInstanceOf(PendingAction::class);
    });
});
