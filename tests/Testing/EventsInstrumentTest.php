<?php

use Iak\Action\Testing\Results\EmittedEvent;
use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\EnumEventAction;
use Iak\Action\Tests\TestClasses\OrderEvent;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Support\Collection;

describe('Events Instrument', function () {
    it('records events emitted by the action under test', function () {
        $received = null;

        ClosureAction::test()
            ->events(function (Collection $events) use (&$received) {
                $received = $events;
            })
            ->handle(function (ClosureAction $action) {
                $action->event('test.event.a', 'payload');
            });

        expect($received)->toHaveCount(1)
            ->and($received->first())->toBeInstanceOf(EmittedEvent::class)
            ->and($received->first()->name)->toBe('test.event.a')
            ->and($received->first()->data)->toBe('payload')
            ->and($received->first()->action)->toBe(ClosureAction::class);
    });

    it('records enum-emitted events matchable with is()', function () {
        $received = null;

        EnumEventAction::test()
            ->events(function (Collection $events) use (&$received) {
                $received = $events;
            })
            ->handle(function (EnumEventAction $action) {
                $action->event(OrderEvent::Placed, ['id' => 7]);
            });

        expect($received->first()->is(OrderEvent::Placed))->toBeTrue()
            ->and($received->first()->name)->toBe('order.placed');
    });

    it('records events emitted by nested actions through proxies', function () {
        $received = null;

        ClosureAction::test()
            ->events(OtherClosureAction::class, function (Collection $events) use (&$received) {
                $received = $events;
            })
            ->handle(function () {
                OtherClosureAction::make()->handle(function (OtherClosureAction $nested) {
                    $nested->event('test.event.b', 'nested-payload');
                });
            });

        expect($received)->toHaveCount(1)
            ->and($received->first()->name)->toBe('test.event.b')
            ->and($received->first()->data)->toBe('nested-payload')
            ->and($received->first()->action)->toBe(OtherClosureAction::class);
    });

    it('records nothing when the action emits no events', function () {
        $received = null;

        ClosureAction::test()
            ->events(function (Collection $events) use (&$received) {
                $received = $events;
            })
            ->handle(fn () => 'quiet');

        expect($received)->toBeEmpty();
    });

    it('keeps user on() listeners registered after an instrumented run', function () {
        // Regression: the events instrument listens on the same
        // instance-scoped names as on() listeners; cleanup must not
        // forget() them wholesale.
        $received = [];
        $action = ClosureAction::make();
        $action->on('test.event.a', function ($data) use (&$received) {
            $received[] = $data;
        });

        (new Testable($action))
            ->events(fn (Collection $events) => null)
            ->handle(function (ClosureAction $a) {
                $a->event('test.event.a', 'during');
            });

        $action->event('test.event.a', 'after');

        expect($received)->toBe(['during', 'after']);
    });
});
