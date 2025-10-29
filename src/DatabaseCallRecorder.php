<?php

namespace Iak\Action;

class DatabaseCallRecorder
{
    public array $calls = [];

    public function record(string $query, array $bindings, float $time, string $connection = 'default'): void
    {
        $this->calls[] = new DatabaseCall($query, $bindings, $time, $connection);
    }

    public function getCalls(): array
    {
        return $this->calls;
    }

    public function getCallCount(): int
    {
        return count($this->calls);
    }

    public function getTotalTime(): float
    {
        return array_sum(array_map(fn($call) => $call->time, $this->calls));
    }

    public function clear(): void
    {
        $this->calls = [];
    }
}
