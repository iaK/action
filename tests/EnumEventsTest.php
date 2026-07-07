<?php

use Iak\Action\EmitsEvents;
use Iak\Action\EventName;
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
