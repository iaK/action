<?php

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
