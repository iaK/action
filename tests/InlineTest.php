<?php

use Iak\Action\Events\ActionCompleted;
use Iak\Action\Events\ActionFailed;
use Iak\Action\Events\ActionStarted;
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
