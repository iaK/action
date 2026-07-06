<?php

use Iak\Action\IdempotentAction;
use Iak\Action\Tests\TestClasses\ArrayNoLockStore;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\InjectingAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('idempotent()', function () {
    it('executes the action once and returns the cached result on subsequent calls', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'result';
        };

        $action = ClosureAction::make();

        $first = $action->idempotent('key')->handle($closure);
        $second = $action->idempotent('key')->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBe('result');
        expect($second)->toBe('result');
    });

    it('scopes keys per action class so different actions do not collide', function () {
        $countA = 0;
        $countB = 0;

        $resultA = ClosureAction::make()->idempotent('shared')->handle(function () use (&$countA) {
            $countA++;

            return 'A';
        });

        $resultB = OtherClosureAction::make()->idempotent('shared')->handle(function () use (&$countB) {
            $countB++;

            return 'B';
        });

        expect($resultA)->toBe('A');
        expect($resultB)->toBe('B');
        expect($countA)->toBe(1);
        expect($countB)->toBe(1);
    });

    it('caches a null result as executed', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return null;
        };

        $action = ClosureAction::make();

        $first = $action->idempotent('null-key')->handle($closure);
        $second = $action->idempotent('null-key')->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBeNull();
        expect($second)->toBeNull();
    });

    it('caches a false result as executed', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return false;
        };

        $action = ClosureAction::make();

        $first = $action->idempotent('false-key')->handle($closure);
        $second = $action->idempotent('false-key')->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBeFalse();
        expect($second)->toBeFalse();
    });

    it('does not consume the key when the action throws', function () {
        $count = 0;

        $action = ClosureAction::make();

        $throwing = function () use (&$count) {
            $count++;

            throw new RuntimeException('boom');
        };

        expect(fn () => $action->idempotent('error-key')->handle($throwing))
            ->toThrow(RuntimeException::class);

        $result = $action->idempotent('error-key')->handle(function () use (&$count) {
            $count++;

            return 'ok';
        });

        expect($count)->toBe(2);
        expect($result)->toBe('ok');
    });

    it('respects the ttl and re-executes once the entry expires', function () {
        Carbon::setTestNow(now());

        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        $action->idempotent('ttl-key', 60)->handle($closure);
        $action->idempotent('ttl-key', 60)->handle($closure);

        expect($count)->toBe(1);

        Carbon::setTestNow(now()->addSeconds(120));

        $action->idempotent('ttl-key', 60)->handle($closure);

        expect($count)->toBe(2);
    });

    it('re-enables execution after forgetIdempotency()', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        $action->idempotent('forget-key')->handle($closure);
        $action->idempotent('forget-key')->handle($closure);

        expect($count)->toBe(1);

        ClosureAction::make()->forgetIdempotency('forget-key');

        $action->idempotent('forget-key')->handle($closure);

        expect($count)->toBe(2);
    });

    it('reports wasExecuted() transitions', function () {
        $wrapper = ClosureAction::make()->idempotent('was-executed-key');

        expect($wrapper->wasExecuted())->toBeNull();

        $wrapper->handle(fn () => 'value');

        expect($wrapper->wasExecuted())->toBeTrue();

        $wrapper->handle(fn () => 'value');

        expect($wrapper->wasExecuted())->toBeFalse();
    });

    it('returns a typed wrapper from idempotent()', function () {
        $wrapper = ClosureAction::make()->idempotent('typed-key');

        expect($wrapper)->toBeInstanceOf(IdempotentAction::class);
    });

    it('works for actions with constructor dependencies', function () {
        $wrapper = InjectingAction::make()->idempotent('dependency-key');

        expect($wrapper->handle())->toBe('typed');
        expect($wrapper->handle())->toBe('typed');
        expect($wrapper->wasExecuted())->toBeFalse();
    });

    it('caches without a lock when the store is not a LockProvider', function () {
        config()->set('cache.stores.nolock', ['driver' => 'nolock']);
        Cache::extend('nolock', fn () => new Repository(new ArrayNoLockStore));

        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        $first = $action->idempotent('nolock-key', null, 'nolock')->handle($closure);
        $second = $action->idempotent('nolock-key', null, 'nolock')->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBe('value');
        expect($second)->toBe('value');
    });
});
