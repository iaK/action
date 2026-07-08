<?php

namespace Iak\Action\Testing\Results;

use Carbon\CarbonInterval;

class Query
{
    public function __construct(
        public string $query,
        /** @var array<mixed> */
        public array $bindings,
        /** Execution time in seconds; use duration() for a unit-safe interval. */
        public float $time,
        public string $connection = 'default',
        public ?string $action = null
    ) {}

    public function duration(): CarbonInterval
    {
        return CarbonInterval::microseconds($this->time * 1_000_000);
    }

    /**
     * The SQL with whitespace runs collapsed and placeholder lists reduced to
     * a single placeholder, so e.g. two whereIn queries with different item
     * counts read as the same statement. Used to group duplicates.
     */
    public function normalizedSql(): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($this->query)) ?? $this->query;

        return preg_replace('/\?(?:\s*,\s*\?)+/', '?', $sql) ?? $sql;
    }

    public function __toString(): string
    {
        $actionInfo = $this->action ? " | Action: {$this->action}" : '';

        return "Query: {$this->query} | Bindings: ".json_encode($this->bindings)." | Time: {$this->duration()->totalMilliseconds}ms{$actionInfo}";
    }
}
