<?php

namespace Iak\Action;

use Attribute;
use InvalidArgumentException;
use UnitEnum;

#[Attribute(Attribute::TARGET_CLASS)]
class EmitsEvents
{
    /** @var array<int, string> */
    public array $events;

    /**
     * @param  array<int, string|UnitEnum>|class-string<UnitEnum>  $events  Event names or enum cases, or an enum class whose cases all become events
     */
    public function __construct(array|string $events)
    {
        if (is_string($events)) {
            if (! enum_exists($events)) {
                throw new InvalidArgumentException(
                    "{$events} is not an enum: pass an array of event names or an enum class-string."
                );
            }

            $events = $events::cases();
        }

        if (empty($events)) {
            throw new InvalidArgumentException('Events array cannot be empty');
        }

        $this->events = array_map(EventName::normalize(...), array_values($events));
    }
}
