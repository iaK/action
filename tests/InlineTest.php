<?php

use Iak\Action\Events\ActionCompleted;
use Iak\Action\Events\ActionFailed;
use Iak\Action\Events\ActionStarted;
use Iak\Action\Execution\TraceEvent;
use Iak\Action\Inline;
use Iak\Action\InlineAction;
use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OrderEvent;
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

    it('stays silent: no lifecycle events without observed()', function () {
        Event::fake([ActionStarted::class, ActionCompleted::class, ActionFailed::class]);

        Inline::handle(fn () => 'done');

        Event::assertNotDispatched(ActionStarted::class);
        Event::assertNotDispatched(ActionCompleted::class);
        Event::assertNotDispatched(ActionFailed::class);
    });

    it('leaves the log context untouched without observed()', function () {
        $seen = Inline::handle(fn (): mixed => Context::get('action'));

        expect($seen)->toBeNull();
        expect(Context::has('action'))->toBeFalse();
    });

    it('dispatches the lifecycle events when observed()', function () {
        Event::fake([ActionStarted::class, ActionCompleted::class, ActionFailed::class]);

        Inline::observed()->handle(fn () => 'done');

        Event::assertDispatched(ActionStarted::class, fn (ActionStarted $e) => $e->action instanceof InlineAction);
        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $e) => $e->result === 'done');
        Event::assertNotDispatched(ActionFailed::class);
    });

    it('dispatches failed and rethrows when an observed closure throws', function () {
        Event::fake([ActionStarted::class, ActionCompleted::class, ActionFailed::class]);

        $throwing = function (): void {
            throw new RuntimeException('boom');
        };

        expect(fn () => Inline::observed()->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        Event::assertDispatched(ActionFailed::class, fn (ActionFailed $e) => $e->exception instanceof RuntimeException);
        Event::assertNotDispatched(ActionCompleted::class);
    });

    it('attributes an observed run in log context and restores after', function () {
        $seen = Inline::observed()->handle(fn (): mixed => Context::get('action'));

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

describe('inline keyless guards', function () {
    it('rejects keyless class-scoped wrappers chained onto an inline action', function (string $method) {
        expect(fn () => Inline::retry()->{$method}())
            ->toThrow(InvalidArgumentException::class, 'explicit key');
    })->with(['circuitBreaker', 'throttle', 'withoutOverlapping', 'memoize']);

    it('accepts the same wrappers with an explicit key', function () {
        $result = Inline::retry()
            ->circuitBreaker('guarded-cb')
            ->throttle('guarded-throttle')
            ->handle(fn () => 'ok');

        expect($result)->toBe('ok');
    });

    it('keeps the class-derived default key for class-based actions', function () {
        $closure = fn () => 'ok';

        expect(ClosureAction::make()->circuitBreaker()->handle($closure))->toBe('ok');
        expect(ClosureAction::make()->throttle()->handle($closure))->toBe('ok');
        expect(ClosureAction::make()->withoutOverlapping()->handle($closure))->toBe('ok');
    });
});

describe('inline events', function () {
    it('declares, listens and emits end to end', function () {
        $heard = null;

        Inline::events(['report.sent'])
            ->on('report.sent', function ($data) use (&$heard) {
                $heard = $data;
            })
            ->handle(fn ($action) => $action->event('report.sent', 'payload'));

        expect($heard)->toBe('payload');
    });

    it('normalizes enum cases interchangeably with their strings', function () {
        $heard = null;

        Inline::events([OrderEvent::Placed])
            ->on('order.placed', function ($data) use (&$heard) {
                $heard = $data;
            })
            ->handle(fn ($action) => $action->event(OrderEvent::Placed, 'enum-payload'));

        expect($heard)->toBe('enum-payload');
    });

    it('rejects emitting an undeclared event', function () {
        $emitting = fn () => Inline::events(['report.sent'])
            ->handle(fn ($action) => $action->event('other.event', null));

        expect($emitting)->toThrow(InvalidArgumentException::class, "Cannot emit event 'other.event'");
    });

    it('rejects listening for an undeclared event', function () {
        expect(fn () => Inline::events(['report.sent'])->on('other.event', fn () => null))
            ->toThrow(InvalidArgumentException::class, "Cannot listen for event 'other.event'");
    });

    it('rejects all events when none were declared', function () {
        expect(fn () => Inline::handle(fn ($action) => $action->event('any.event', null)))
            ->toThrow(InvalidArgumentException::class, "Cannot emit event 'any.event'");
    });

    it('keeps the chain through on(): wrappers configured before still apply', function () {
        $count = 0;
        $closure = function ($action) use (&$count) {
            $count++;

            return 'result';
        };

        $first = Inline::events(['e.done'])->idempotent('on-chain')->on('e.done', fn () => null);
        $second = Inline::events(['e.done'])->idempotent('on-chain')->on('e.done', fn () => null);

        expect($first)->toBeInstanceOf(PendingAction::class);
        expect($first->handle($closure))->toBe('result');
        expect($second->handle($closure))->toBe('result');
        expect($count)->toBe(1);
        expect($second->wasExecuted())->toBeFalse();
    });

    it('keeps the chain through on() for class-based actions too', function () {
        $chain = ClosureAction::make()->idempotent('class-on-chain')->on('test.event.a', fn () => null);

        expect($chain)->toBeInstanceOf(PendingAction::class);

        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'ok';
        };

        expect($chain->handle($closure))->toBe('ok');
        expect(ClosureAction::make()->idempotent('class-on-chain')->handle($closure))->toBe('ok');
        expect($count)->toBe(1);
    });
});
