<?php

use Iak\Action\EmitsEvents;
use Iak\Action\EventName;
use Iak\Action\Tests\TestClasses\EnumEventAction;
use Iak\Action\Tests\TestClasses\IntBackedEvent;
use Iak\Action\Tests\TestClasses\OrderEvent;
use Iak\Action\Tests\TestClasses\PureEvent;

describe('EventName', function () {
    it('passes strings through untouched', function () {
        expect(EventName::normalize('order.placed'))->toBe('order.placed');
    });

    it('uses the value of string-backed enums', function () {
        expect(EventName::normalize(OrderEvent::Placed))->toBe('order.placed');
    });

    it('uses the case name of pure enums', function () {
        expect(EventName::normalize(PureEvent::Started))->toBe('Started');
    });

    it('rejects int-backed enums', function () {
        expect(fn () => EventName::normalize(IntBackedEvent::One))
            ->toThrow(InvalidArgumentException::class, IntBackedEvent::class);
    });
});

describe('EmitsEvents enum support', function () {
    it('expands an enum class-string to all its cases', function () {
        expect((new EmitsEvents(OrderEvent::class))->events)
            ->toBe(['order.placed', 'order.shipped']);
    });

    it('expands pure enum classes by case name', function () {
        expect((new EmitsEvents(PureEvent::class))->events)
            ->toBe(['Started', 'Finished']);
    });

    it('normalizes enum cases mixed into the array form', function () {
        expect((new EmitsEvents([OrderEvent::Placed, 'legacy']))->events)
            ->toBe(['order.placed', 'legacy']);
    });

    it('rejects non-enum class-strings', function () {
        expect(fn () => new EmitsEvents('NotAnEnum'))
            ->toThrow(InvalidArgumentException::class, 'NotAnEnum is not an enum');
    });

    it('rejects int-backed enum classes', function () {
        expect(fn () => new EmitsEvents(IntBackedEvent::class))
            ->toThrow(InvalidArgumentException::class, IntBackedEvent::class);
    });
});

describe('HandlesEvents enum support', function () {
    it('emits and listens with enum cases', function () {
        $received = null;

        EnumEventAction::make()
            ->on(OrderEvent::Placed, function ($data) use (&$received) {
                $received = $data;
            })
            ->event(OrderEvent::Placed, 'payload');

        expect($received)->toBe('payload');
    });

    it('treats an enum case and its normalized string as the same event', function () {
        $received = [];

        $action = EnumEventAction::make()
            ->on('order.placed', function ($data) use (&$received) {
                $received[] = ['string-listener', $data];
            })
            ->on(OrderEvent::Placed, function ($data) use (&$received) {
                $received[] = ['enum-listener', $data];
            });

        $action->event(OrderEvent::Placed, 'first');
        $action->event('order.placed', 'second');

        expect($received)->toBe([
            ['string-listener', 'first'],
            ['enum-listener', 'first'],
            ['string-listener', 'second'],
            ['enum-listener', 'second'],
        ]);
    });

    it('rejects disallowed enum events with the normalized name', function () {
        $action = EnumEventAction::make();

        // PureEvent cases are not in OrderEvent's allowed list
        expect(fn () => $action->event(PureEvent::Started, []))
            ->toThrow(InvalidArgumentException::class, "Cannot emit event 'Started'.");
    });

    it('forwards events declared as enum cases', function () {
        $received = [];

        EnumEventAction::make()
            ->on(OrderEvent::Placed, function ($data) use (&$received) {
                $received[] = $data;
            })
            ->handle(function () {
                EnumEventAction::make()
                    ->forwardEvents([OrderEvent::Placed])
                    ->handle(function (EnumEventAction $inner) {
                        $inner->event(OrderEvent::Placed, 'forwarded');
                    });
            });

        expect($received)->toContain('forwarded');
    });
});
