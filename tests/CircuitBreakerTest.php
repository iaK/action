<?php

use Iak\Action\Exceptions\CircuitOpenException;
use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    Carbon::setTestNow(now());
});

afterEach(function () {
    Carbon::setTestNow();
});

function failBreaker(int $times, string $key = 'svc', int $threshold = 2, int $cooldown = 60): int
{
    $ran = 0;

    $throwing = function () use (&$ran) {
        $ran++;

        throw new RuntimeException('dependency down');
    };

    for ($i = 0; $i < $times; $i++) {
        try {
            ClosureAction::make()->circuitBreaker($key, threshold: $threshold, cooldown: $cooldown)->handle($throwing);
        } catch (RuntimeException) {
            // Both the dependency failure and an open circuit land here.
        }
    }

    return $ran;
}

describe('circuitBreaker()', function () {
    it('passes results through while the breaker is closed', function () {
        $result = ClosureAction::make()->circuitBreaker('svc')->handle(fn () => 'ok');

        expect($result)->toBe('ok');
    });

    it('rejects a threshold or cooldown below one', function () {
        expect(fn () => ClosureAction::make()->circuitBreaker('svc', threshold: 0))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ClosureAction::make()->circuitBreaker('svc', cooldown: 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('opens after the threshold of consecutive failures and stops executing', function () {
        expect(failBreaker(2))->toBe(2);

        $ran = false;

        expect(fn () => ClosureAction::make()->circuitBreaker('svc', threshold: 2)->handle(function () use (&$ran) {
            $ran = true;
        }))->toThrow(CircuitOpenException::class);

        expect($ran)->toBeFalse();
    });

    it('reports the remaining cooldown on the exception', function () {
        failBreaker(2, cooldown: 60);

        Carbon::setTestNow(now()->addSeconds(45));

        try {
            ClosureAction::make()->circuitBreaker('svc', threshold: 2, cooldown: 60)->handle(fn () => 'ok');
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException $e) {
            expect($e->availableIn())->toBe(15);
            expect($e->key())->toBe('svc');
        }
    });

    it('a success resets the failure streak', function () {
        failBreaker(1);

        // A success in between: the streak starts over.
        ClosureAction::make()->circuitBreaker('svc', threshold: 2)->handle(fn () => 'ok');

        // One more failure is again below the threshold, so the next call executes.
        failBreaker(1);

        $result = ClosureAction::make()->circuitBreaker('svc', threshold: 2)->handle(fn () => 'ok');

        expect($result)->toBe('ok');
    });

    it('closes again after a successful probe once the cooldown has passed', function () {
        failBreaker(2, cooldown: 60);

        Carbon::setTestNow(now()->addSeconds(61));

        $probe = ClosureAction::make()->circuitBreaker('svc', threshold: 2, cooldown: 60)->handle(fn () => 'recovered');

        expect($probe)->toBe('recovered');

        // Fully closed: a single new failure does not re-open it.
        expect(failBreaker(1))->toBe(1);

        $result = ClosureAction::make()->circuitBreaker('svc', threshold: 2)->handle(fn () => 'ok');

        expect($result)->toBe('ok');
    });

    it('re-opens for a fresh cooldown when the probe fails', function () {
        failBreaker(2, cooldown: 60);

        Carbon::setTestNow(now()->addSeconds(61));

        // The probe executes and fails.
        expect(failBreaker(1))->toBe(1);

        // Immediately after: open again, nothing executes.
        $ran = false;

        expect(fn () => ClosureAction::make()->circuitBreaker('svc', threshold: 2, cooldown: 60)->handle(function () use (&$ran) {
            $ran = true;
        }))->toThrow(CircuitOpenException::class);

        expect($ran)->toBeFalse();
    });

    it('scopes the breaker to the action class by default', function () {
        $throwing = function () {
            throw new RuntimeException('down');
        };

        for ($i = 0; $i < 2; $i++) {
            try {
                ClosureAction::make()->circuitBreaker(threshold: 2)->handle($throwing);
            } catch (RuntimeException) {
            }
        }

        // ClosureAction's default-keyed breaker is open...
        expect(fn () => ClosureAction::make()->circuitBreaker(threshold: 2)->handle(fn () => 'ok'))
            ->toThrow(CircuitOpenException::class);

        // ...but OtherClosureAction's is untouched.
        $result = OtherClosureAction::make()->circuitBreaker(threshold: 2)->handle(fn () => 'ok');

        expect($result)->toBe('ok');
    });

    it('shares an explicitly keyed breaker between actions', function () {
        failBreaker(2, key: 'shared-dependency');

        expect(fn () => OtherClosureAction::make()->circuitBreaker('shared-dependency', threshold: 2)->handle(fn () => 'ok'))
            ->toThrow(CircuitOpenException::class);
    });

    it('is not retried by retry(): an open circuit fails fast', function () {
        $attempts = 0;

        $throwing = function () use (&$attempts) {
            $attempts++;

            throw new RuntimeException('down');
        };

        // threshold 2, but 5 retry attempts: the third attempt meets an open
        // circuit, which retry() must not retry (NonRetryable).
        expect(fn () => ClosureAction::make()
            ->retry(times: 5)
            ->circuitBreaker('svc', threshold: 2)
            ->handle($throwing))->toThrow(CircuitOpenException::class);

        expect($attempts)->toBe(2);
    });

    it('returns the chainable wrapper', function () {
        expect(ClosureAction::make()->circuitBreaker('svc'))->toBeInstanceOf(PendingAction::class);
    });
});
