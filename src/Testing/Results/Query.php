<?php

namespace Iak\Action\Testing\Results;

use Carbon\CarbonInterval;

class Query
{
    public function __construct(
        public string $query,
        /** @var array<mixed> */
        public array $bindings,
        public float $time,
        public string $connection = 'default',
        public ?string $action = null
    ) {}

    public function duration(): CarbonInterval
    {
        return CarbonInterval::microseconds($this->time * 1_000_000);
    }

    public function __toString(): string
    {
        $actionInfo = $this->action ? " | Action: {$this->action}" : '';

        return "Query: {$this->query} | Bindings: ".json_encode($this->bindings)." | Time: {$this->duration()->totalMilliseconds}ms{$actionInfo}";
    }
}
