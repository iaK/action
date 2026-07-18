<?php

use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ArrayNoLockStore;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\InjectingAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    it('uses the given key verbatim as the cache key', function () {
        ClosureAction::make()->idempotent('verbatim-key')->handle(fn () => 'value');

        expect(Cache::get('verbatim-key'))->toBe(['result' => 'value']);
    });

    it('shares the key across action classes', function () {
        $countB = 0;

        $resultA = ClosureAction::make()->idempotent('shared')->handle(fn () => 'A');

        $resultB = OtherClosureAction::make()->idempotent('shared')->handle(function () use (&$countB) {
            $countB++;

            return 'B';
        });

        expect($resultA)->toBe('A');
        expect($resultB)->toBe('A');
        expect($countB)->toBe(0);
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

        expect($wrapper)->toBeInstanceOf(PendingAction::class);
    });

    it('runs once via run() and shares the cache entry with handle()', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        $viaRun = $action->idempotent('run-key')->run(function (ClosureAction $received) use ($action, $closure) {
            expect($received)->toBe($action);

            return $received->handle($closure);
        });

        // The same key is already consumed, whichever entry point is used.
        $viaHandle = $action->idempotent('run-key')->handle($closure);
        $viaRunAgain = $action->idempotent('run-key')->run(fn (ClosureAction $a) => $a->handle($closure));

        expect($count)->toBe(1);
        expect($viaRun)->toBe('value');
        expect($viaHandle)->toBe('value');
        expect($viaRunAgain)->toBe('value');
    });

    it('reports wasExecuted() transitions for run()', function () {
        $wrapper = ClosureAction::make()->idempotent('run-executed-key');

        expect($wrapper->wasExecuted())->toBeNull();

        $wrapper->run(fn (ClosureAction $a) => $a->handle(fn () => 'value'));

        expect($wrapper->wasExecuted())->toBeTrue();

        $wrapper->run(fn (ClosureAction $a) => $a->handle(fn () => 'value'));

        expect($wrapper->wasExecuted())->toBeFalse();
    });

    it('forwards non-handle calls to the wrapped action', function () {
        $wrapper = ClosureAction::make()->idempotent('forward-key');

        $wrapper->handle(fn () => 'value');

        // forgetIdempotency() lives on the action; the wrapper forwards it.
        $wrapper->forgetIdempotency('forward-key');

        $count = 0;
        ClosureAction::make()->idempotent('forward-key')->handle(function () use (&$count) {
            $count++;

            return 'value';
        });

        expect($count)->toBe(1);
    });

    it('works for actions with constructor dependencies', function () {
        $wrapper = InjectingAction::make()->idempotent('dependency-key');

        expect($wrapper->handle())->toBe('typed');
        expect($wrapper->handle())->toBe('typed');
        expect($wrapper->wasExecuted())->toBeFalse();
    });

    it('does not consume the key when a surrounding database transaction rolls back', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        try {
            DB::transaction(function () use ($action, $closure) {
                $action->idempotent('tx-rollback-key')->handle($closure);

                throw new RuntimeException('roll it back');
            });
        } catch (RuntimeException) {
        }

        expect($count)->toBe(1);

        // The rolled-back run must not have consumed the key.
        $result = $action->idempotent('tx-rollback-key')->handle($closure);

        expect($result)->toBe('value');
        expect($count)->toBe(2);
    });

    it('consumes the key once a surrounding database transaction commits', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        DB::transaction(function () use ($action, $closure) {
            $wrapper = $action->idempotent('tx-commit-key');

            expect($wrapper->handle($closure))->toBe('value');
            expect($wrapper->wasExecuted())->toBeTrue();
        });

        $result = $action->idempotent('tx-commit-key')->handle($closure);

        expect($result)->toBe('value');
        expect($count)->toBe(1);
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
