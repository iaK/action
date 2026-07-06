<?php

namespace Iak\Action\Testing\Results;

use Carbon\CarbonInterval;

class Profile
{
    public function __construct(
        public string $class,
        public float $start,
        public float $end,
        public int $startMemory = 0,
        public int $endMemory = 0,
        public int $peakMemory = 0,
        /** @var Memory[] */
        public array $memoryRecords = []
    ) {}

    public function duration(): CarbonInterval
    {
        return CarbonInterval::microseconds(($this->end - $this->start) * 1_000_000)->cascade();
    }

    public function memoryUsed(): MemorySize
    {
        return new MemorySize($this->endMemory - $this->startMemory);
    }

    public function startMemory(): MemorySize
    {
        return new MemorySize($this->startMemory);
    }

    public function endMemory(): MemorySize
    {
        return new MemorySize($this->endMemory);
    }

    public function peakMemory(): MemorySize
    {
        return new MemorySize($this->peakMemory);
    }

    /**
     * @return Memory[]
     */
    public function records(): array
    {
        return $this->memoryRecords;
    }

    public function __toString(): string
    {
        $memoryInfo = $this->startMemory > 0 || $this->endMemory > 0 || $this->peakMemory > 0
            ? " (memory: {$this->memoryUsed()}, peak: {$this->peakMemory()})"
            : '';

        return "{$this->class} took {$this->duration()->totalMilliseconds}ms{$memoryInfo}";
    }
}
