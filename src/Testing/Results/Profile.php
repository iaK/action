<?php

namespace Iak\Action\Testing\Results;

use Carbon\CarbonInterval;
use Iak\Action\Testing\MemoryFormatter;

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

    public function memoryUsed(?string $unit = null): int|string
    {
        $formatter = new MemoryFormatter($this->endMemory - $this->startMemory);

        if ($unit === null) {
            return $formatter->formatBytes();
        }

        return $formatter->convertToUnit($unit);
    }

    public function startMemory(?string $unit = null): int|string
    {
        $formatter = new MemoryFormatter($this->startMemory);

        if ($unit === null) {
            return $formatter->formatBytes();
        }

        return $formatter->convertToUnit($unit);
    }

    public function endMemory(?string $unit = null): int|string
    {
        $formatter = new MemoryFormatter($this->endMemory);

        if ($unit === null) {
            return $formatter->formatBytes();
        }

        return $formatter->convertToUnit($unit);
    }

    public function peakMemory(?string $unit = null): int|string
    {
        $formatter = new MemoryFormatter($this->peakMemory);

        if ($unit === null) {
            return $formatter->formatBytes();
        }

        return $formatter->convertToUnit($unit);
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
