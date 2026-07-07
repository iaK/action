<?php

namespace Iak\Action\Testing\Results;

use Iak\Action\EventName;
use UnitEnum;

class EmittedEvent
{
    public function __construct(
        public string $name,
        public mixed $data,
        public ?string $action = null
    ) {}

    /**
     * Whether this record is the given event, comparing enum cases and plain
     * strings by their normalized event name.
     */
    public function is(string|UnitEnum $event): bool
    {
        return $this->name === EventName::normalize($event);
    }

    public function __toString(): string
    {
        $actionInfo = $this->action ? " | Action: {$this->action}" : '';

        return "Event: {$this->name} | Data: ".json_encode($this->data).$actionInfo;
    }
}
