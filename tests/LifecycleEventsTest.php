<?php

use Iak\Action\Events\ActionCompleted;
use Iak\Action\Events\ActionFailed;
use Iak\Action\Events\ActionStarted;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    Event::fake([ActionStarted::class, ActionCompleted::class, ActionFailed::class]);
});

describe('lifecycle events', function () {
    it('dispatches started and completed around an observed run', function () {
        $action = ClosureAction::make();

        $result = $action->observed()->handle(fn () => 'done');

        expect($result)->toBe('done');

        Event::assertDispatched(ActionStarted::class, fn (ActionStarted $e) => $e->action === $action);
        Event::assertDispatched(ActionCompleted::class, function (ActionCompleted $e) use ($action) {
            return $e->action === $action
                && $e->result === 'done'
                && $e->durationMs >= 0;
        });
        Event::assertNotDispatched(ActionFailed::class);
    });

    it('dispatches failed with the exception and rethrows when the run throws', function () {
        $action = ClosureAction::make();

        $throwing = function () {
            throw new RuntimeException('boom');
        };

        expect(fn () => $action->observed()->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        Event::assertDispatched(ActionFailed::class, function (ActionFailed $e) use ($action) {
            return $e->action === $action
                && $e->exception instanceof RuntimeException
                && $e->exception->getMessage() === 'boom'
                && $e->durationMs >= 0;
        });
        Event::assertNotDispatched(ActionCompleted::class);
    });

    it('fires for every wrapper feature, not just observed()', function () {
        ClosureAction::make()->retry(times: 2)->handle(fn () => 'done');

        Event::assertDispatched(ActionStarted::class);
        Event::assertDispatched(ActionCompleted::class);
    });

    it('fires on an idempotent cache hit, carrying the cached result', function () {
        $action = ClosureAction::make();

        $action->idempotent('observed-key')->handle(fn () => 'cached');
        $action->idempotent('observed-key')->handle(fn () => 'never');

        Event::assertDispatchedTimes(ActionCompleted::class, 2);
        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $e) => $e->result === 'cached');
    });

    it('fires for the run() path', function () {
        ClosureAction::make()->observed()->run(fn (ClosureAction $a) => $a->handle(fn () => 'via-run'));

        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $e) => $e->result === 'via-run');
    });

    it('does not fire for a plain unwrapped handle()', function () {
        ClosureAction::make()->handle(fn () => 'plain');

        Event::assertNotDispatched(ActionStarted::class);
        Event::assertNotDispatched(ActionCompleted::class);
    });

    it('reports the fallback value as the completed result', function () {
        $result = ClosureAction::make()
            ->fallback(fn (Throwable $e) => 'degraded')
            ->handle(function () {
                throw new RuntimeException('boom');
            });

        expect($result)->toBe('degraded');

        // The invocation as a whole completed — with the fallback's answer.
        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $e) => $e->result === 'degraded');
        Event::assertNotDispatched(ActionFailed::class);
    });
});
