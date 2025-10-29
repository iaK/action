<?php

namespace Iak\Action\Testing;

use Carbon\CarbonInterval;

class Measurement
{
    public function __construct(
        public $class,
        public float $start,
        public float $end
    ) {}

    public function duration(): CarbonInterval
    {
        return CarbonInterval::microseconds(($this->end - $this->start) * 1_000_000);
    }

    public function __toString(): string
    {
        return "{$this->class} took {$this->duration()->totalMilliseconds}ms";
    }
}
