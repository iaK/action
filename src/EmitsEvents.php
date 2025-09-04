<?php

namespace Iak\Action;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class EmitsEvents
{
    public function __construct(public array $events)
    {
        if (empty($events)) {
            throw new InvalidArgumentException('Events array cannot be empty');
        }

        foreach ($events as $event) {
            if (! is_string($event)) {
                throw new InvalidArgumentException('All events must be strings');
            }
        }
    }
}
