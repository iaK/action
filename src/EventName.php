<?php

namespace Iak\Action;

use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

/**
 * Maps the string|UnitEnum event identifiers accepted by the public event API
 * onto the internal string event names everything downstream keys on: strings
 * pass through, string-backed enums use their value, pure enums their case
 * name. Int-backed enums are rejected — their values make meaningless event
 * names.
 *
 * @internal
 */
final class EventName
{
    public static function normalize(string|UnitEnum $event): string
    {
        if (is_string($event)) {
            return $event;
        }

        if ($event instanceof BackedEnum) {
            if (is_int($event->value)) {
                throw new InvalidArgumentException(
                    'Int-backed enum '.$event::class.' cannot name events: back it with strings or use a pure enum.'
                );
            }

            return $event->value;
        }

        return $event->name;
    }
}
