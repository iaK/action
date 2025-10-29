<?php

namespace Iak\Action;

use Carbon\CarbonInterval;

class DatabaseCall
{
    public function __construct(
        public string $query,
        public array $bindings,
        public float $time,
        public string $connection = 'default'
    ) {}

    public function duration(): CarbonInterval
    {
        return CarbonInterval::microseconds($this->time * 1_000_000);
    }

    public function __toString(): string
    {
        return "Query: {$this->query} | Bindings: " . json_encode($this->bindings) . " | Time: {$this->duration()->totalMilliseconds}ms";
    }
}
