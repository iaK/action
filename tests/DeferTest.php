<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

describe('defer()', function () {
    it('registers the run for later instead of executing immediately', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'done';
        };

        ClosureAction::make()->defer(fn (ClosureAction $action) => $action->handle($closure));

        expect($count)->toBe(0);

        app(DeferredCallbackCollection::class)->invoke();

        expect($count)->toBe(1);
    });

    it('runs the whole wrapper chain when the deferred callback fires', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'done';
        };

        ClosureAction::make()
            ->idempotent('deferred-key')
            ->defer(fn (ClosureAction $action) => $action->handle($closure));

        app(DeferredCallbackCollection::class)->invoke();

        expect($count)->toBe(1);

        // The deferred run consumed the idempotency key like any other run.
        $result = ClosureAction::make()->idempotent('deferred-key')->handle($closure);

        expect($result)->toBe('done');
        expect($count)->toBe(1);
    });
});
