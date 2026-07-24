<?php

use Iak\Action\Events\ActionCompleted;
use Iak\Action\Events\ActionFailed;
use Iak\Action\Exceptions\OnceConsumedException;
use Iak\Action\Execution\TraceEvent;
use Iak\Action\Inline;
use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ArrayNoLockStore;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('once()', function () {
    it('executes on the first call and skips later calls, answering null', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'result';
        };

        $action = ClosureAction::make();

        $first = $action->once('key')->handle($closure);
        $second = $action->once('key')->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBe('result');
        expect($second)->toBeNull();
    });

    it('stores a bare true marker under the verbatim key, never the result', function () {
        ClosureAction::make()->once('marker-key')->handle(fn () => 'secret');

        expect(Cache::get('marker-key'))->toBe(true);
    });

    it('skips when the key already exists in the cache, whoever wrote it', function () {
        Cache::put('external-key', 'written elsewhere');

        $count = 0;
        $result = ClosureAction::make()->once('external-key')->handle(function () use (&$count) {
            $count++;

            return 'ran';
        });

        expect($count)->toBe(0);
        expect($result)->toBeNull();
        expect(Cache::get('external-key'))->toBe('written elsewhere');
    });

    it('shares the key across action classes', function () {
        $countB = 0;

        ClosureAction::make()->once('shared')->handle(fn () => 'A');

        $resultB = OtherClosureAction::make()->once('shared')->handle(function () use (&$countB) {
            $countB++;

            return 'B';
        });

        expect($countB)->toBe(0);
        expect($resultB)->toBeNull();
    });

    it('does not consume the key when the action throws', function () {
        $count = 0;

        $action = ClosureAction::make();

        $throwing = function () use (&$count) {
            $count++;

            throw new RuntimeException('boom');
        };

        expect(fn () => $action->once('error-key')->handle($throwing))
            ->toThrow(RuntimeException::class);

        $result = $action->once('error-key')->handle(function () use (&$count) {
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

        $action->once('ttl-key', 60)->handle($closure);
        $action->once('ttl-key', 60)->handle($closure);

        expect($count)->toBe(1);

        Carbon::setTestNow(now()->addSeconds(120));

        $action->once('ttl-key', 60)->handle($closure);

        expect($count)->toBe(2);
    });

    it('reports wasExecuted() transitions', function () {
        $wrapper = ClosureAction::make()->once('was-executed-key');

        expect($wrapper->wasExecuted())->toBeNull();

        $wrapper->handle(fn () => 'value');

        expect($wrapper->wasExecuted())->toBeTrue();

        $wrapper->handle(fn () => 'value');

        expect($wrapper->wasExecuted())->toBeFalse();
    });

    it('returns a typed wrapper from once()', function () {
        $wrapper = ClosureAction::make()->once('typed-key');

        expect($wrapper)->toBeInstanceOf(PendingAction::class);
    });

    it('runs once via then() and shares the key with handle()', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        $viaThen = $action->once('run-key')->then(fn (ClosureAction $a) => $a->handle($closure));

        $viaHandle = $action->once('run-key')->handle($closure);

        expect($count)->toBe(1);
        expect($viaThen)->toBe('value');
        expect($viaHandle)->toBeNull();
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
                $action->once('tx-rollback-key')->handle($closure);

                throw new RuntimeException('roll it back');
            });
        } catch (RuntimeException) {
        }

        expect($count)->toBe(1);

        // The rolled-back run must not have consumed the key.
        $result = $action->once('tx-rollback-key')->handle($closure);

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
            $wrapper = $action->once('tx-commit-key');

            expect($wrapper->handle($closure))->toBe('value');
            expect($wrapper->wasExecuted())->toBeTrue();
        });

        $result = $action->once('tx-commit-key')->handle($closure);

        expect($result)->toBeNull();
        expect($count)->toBe(1);
    });

    it('consumes the key without a lock when the store is not a LockProvider', function () {
        config()->set('cache.stores.nolock', ['driver' => 'nolock']);
        Cache::extend('nolock', fn () => new Repository(new ArrayNoLockStore));

        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        $first = $action->once('nolock-key', null, 'nolock')->handle($closure);
        $second = $action->once('nolock-key', null, 'nolock')->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBe('value');
        expect($second)->toBeNull();
    });

    it('runs closures at most once via Inline::once()', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'ran';
        };

        $first = Inline::once('inline-once-key')->handle($closure);
        $second = Inline::once('inline-once-key')->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBe('ran');
        expect($second)->toBeNull();
    });

    it('routes a consumed key through fallback(), which receives the OnceConsumedException', function () {
        $received = null;

        $action = ClosureAction::make();

        $action->once('rescued-key')->handle(fn () => 'real');

        $second = $action->once('rescued-key')
            ->fallback(function (Throwable $e) use (&$received) {
                $received = $e;

                return 'rescued';
            })
            ->handle(fn () => 'real');

        expect($second)->toBe('rescued');
        expect($received)->toBeInstanceOf(OnceConsumedException::class);
        expect($received->key())->toBe('rescued-key');
    });

    it('propagates an OnceConsumedException rethrown from the fallback', function () {
        $action = ClosureAction::make();

        $action->once('declined-key')->handle(fn () => 'real');

        $wrapper = $action->once('declined-key')->fallback(function (Throwable $e) {
            throw $e;
        });

        expect(fn () => $wrapper->handle(fn () => 'real'))
            ->toThrow(OnceConsumedException::class, 'The once key [declined-key] is already consumed.');
    });

    it('still reports wasExecuted() false when the fallback rescues a skip', function () {
        $action = ClosureAction::make();

        $action->once('was-executed-fallback-key')->handle(fn () => 'real');

        $wrapper = $action->once('was-executed-fallback-key')->fallback(fn (Throwable $e) => 'rescued');
        $wrapper->handle(fn () => 'real');

        expect($wrapper->wasExecuted())->toBeFalse();
    });

    it('does not consume an outer idempotent() key when the once key is consumed', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $action = ClosureAction::make();

        $action->once('inner-once-key')->handle($closure);

        // The skip propagates as an exception through idempotent(), so the
        // idempotency key must stay free.
        $skipped = $action->idempotent('outer-idem-key')->once('inner-once-key')->handle($closure);

        expect($skipped)->toBeNull();
        expect($count)->toBe(1);

        $real = $action->idempotent('outer-idem-key')->handle($closure);

        expect($real)->toBe('value');
        expect($count)->toBe(2);
    });

    it('records OnceHit and FallbackUsed in the trace when a fallback rescues a skip', function () {
        $action = ClosureAction::make();

        $action->once('traced-once-key')->handle(fn () => 'real');

        $wrapper = $action->once('traced-once-key')
            ->fallback(fn (Throwable $e) => 'rescued')
            ->trace();

        $wrapper->handle(fn () => 'real');

        expect($wrapper->lastTrace()->has(TraceEvent::OnceHit))->toBeTrue();
        expect($wrapper->lastTrace()->has(TraceEvent::FallbackUsed))->toBeTrue();

        expect($wrapper->lastTrace()->first(TraceEvent::FallbackUsed)?->context['exception'])
            ->toBe(OnceConsumedException::class);

        $events = array_column($wrapper->lastTrace()->entries(), 'event');

        expect(array_search(TraceEvent::OnceHit, $events, true))
            ->toBeLessThan(array_search(TraceEvent::FallbackUsed, $events, true));
    });

    it('dispatches ActionCompleted, not ActionFailed, for an observed converted skip', function () {
        Event::fake([ActionCompleted::class, ActionFailed::class]);

        $action = ClosureAction::make();

        $action->once('observed-skip-key')->handle(fn () => 'real');
        $result = $action->once('observed-skip-key')->observed()->handle(fn () => 'real');

        expect($result)->toBeNull();
        Event::assertDispatched(ActionCompleted::class);
        Event::assertNotDispatched(ActionFailed::class);
    });

    it('dispatches ActionCompleted, not ActionFailed, for an observed rescued skip', function () {
        Event::fake([ActionCompleted::class, ActionFailed::class]);

        $action = ClosureAction::make();

        $action->once('observed-rescue-key')->handle(fn () => 'real');

        $result = $action->once('observed-rescue-key')
            ->fallback(fn (Throwable $e) => 'rescued')
            ->observed()
            ->handle(fn () => 'real');

        expect($result)->toBe('rescued');
        Event::assertDispatched(ActionCompleted::class);
        Event::assertNotDispatched(ActionFailed::class);
    });
});
