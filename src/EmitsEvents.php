<?php

namespace Iak\Action;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class EmitsEvents
{

    public function __construct(
        /** @var string[] */
        public array $events
    )
    {
        if (empty($events)) {
            throw new InvalidArgumentException('Events array cannot be empty');
        }
    }
}
