<?php

use Iak\Action\Events\ActionCompleted;
use Iak\Action\Events\ActionFailed;
use Iak\Action\Events\ActionStarted;
use Iak\Action\Execution\TraceEvent;
use Iak\Action\Inline;
use Iak\Action\InlineAction;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
});

describe('bare Inline::handle()', function () {
    it('runs the closure and returns its value', function () {
        expect(Inline::handle(fn () => 'done'))->toBe('done');
    });

    it('passes the inline action to the closure', function () {
        $received = Inline::handle(fn ($action) => $action);

        expect($received)->toBeInstanceOf(InlineAction::class);
    });

    it('dispatches the lifecycle events around a bare run', function () {
        Event::fake([ActionStarted::class, ActionCompleted::class, ActionFailed::class]);

        Inline::handle(fn () => 'done');

        Event::assertDispatched(ActionStarted::class, fn (ActionStarted $e) => $e->action instanceof InlineAction);
        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $e) => $e->result === 'done');
        Event::assertNotDispatched(ActionFailed::class);
    });

    it('dispatches failed and rethrows when the closure throws', function () {
        Event::fake([ActionStarted::class, ActionCompleted::class, ActionFailed::class]);

        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        expect(fn () => Inline::handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        Event::assertDispatched(ActionFailed::class, fn (ActionFailed $e) => $e->exception instanceof RuntimeException);
        Event::assertNotDispatched(ActionCompleted::class);
    });

    it('attributes the run in log context and restores after', function () {
        $seen = Inline::handle(fn (): mixed => Context::get('action'));

        expect($seen)->toBe(InlineAction::class);
        expect(Context::has('action'))->toBeFalse();
    });
});

describe('Inline::defer()', function () {
    it('registers the run for later and passes the action to the callback', function () {
        $count = 0;
        $received = null;

        Inline::defer(function ($action) use (&$count, &$received) {
            $count++;
            $received = $action;
        });

        expect($count)->toBe(0);

        app(DeferredCallbackCollection::class)->invoke();

        expect($count)->toBe(1);
        expect($received)->toBeInstanceOf(InlineAction::class);
    });
});

describe('inline wrappers', function () {
    it('idempotent() caches across separate chains sharing a key', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'result';
        };

        $first = Inline::idempotent('inline-idem')->handle($closure);

        $chain = Inline::idempotent('inline-idem');
        $second = $chain->handle($closure);

        expect($count)->toBe(1);
        expect($first)->toBe('result');
        expect($second)->toBe('result');
        expect($chain->wasExecuted())->toBeFalse();
    });

    it('forgetIdempotency() frees the key for the next run', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'result';
        };

        Inline::idempotent('inline-forget')->handle($closure);
        Inline::forgetIdempotency('inline-forget');
        Inline::idempotent('inline-forget')->handle($closure);

        expect($count)->toBe(2);
    });

    it('retry() re-runs a throwing closure', function () {
        $attempts = 0;
        $flaky = function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new RuntimeException('flaky');
            }

            return 'recovered';
        };

        expect(Inline::retry(3)->handle($flaky))->toBe('recovered');
        expect($attempts)->toBe(3);
    });

    it('fallback() rescues and composes with retry()', function () {
        $attempts = 0;
        $failing = function () use (&$attempts): void {
            $attempts++;

            throw new RuntimeException('down');
        };

        $result = Inline::fallback(fn (Throwable $e) => 'degraded')
            ->retry(2)
            ->handle($failing);

        expect($result)->toBe('degraded');
        expect($attempts)->toBe(2);
    });

    it('runs the keyed concurrency wrappers end to end', function () {
        expect(Inline::circuitBreaker('inline-cb')->handle(fn () => 'ok'))->toBe('ok');
        expect(Inline::throttle('inline-throttle')->handle(fn () => 'ok'))->toBe('ok');
        expect(Inline::withoutOverlapping('inline-lock')->handle(fn () => 'ok'))->toBe('ok');
    });

    it('memoize() remembers per process under its required key', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'memo';
        };

        expect(Inline::memoize('inline-memo')->handle($closure))->toBe('memo');
        expect(Inline::memoize('inline-memo')->handle($closure))->toBe('memo');
        expect($count)->toBe(1);
    });

    it('traces an inline run', function () {
        $chain = Inline::idempotent('inline-traced')->trace();
        $chain->handle(fn () => 'traced');

        expect($chain->lastTrace())->not->toBeNull();
        expect($chain->lastTrace()->has(TraceEvent::IdempotencyStored))->toBeTrue();
    });
});
