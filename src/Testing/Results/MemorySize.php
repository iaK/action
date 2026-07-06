<?php

namespace Iak\Action\Testing\Results;

use InvalidArgumentException;
use Stringable;

class MemorySize implements Stringable
{
    public function __construct(
        public readonly int $bytes,
    ) {}

    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Convert to the given unit, rounded to two decimals
     */
    public function in(string $unit): float
    {
        $divisor = match (strtoupper($unit)) {
            'B', 'BYTES' => 1,
            'KB', 'KILOBYTES' => 1024,
            'MB', 'MEGABYTES' => 1024 ** 2,
            'GB', 'GIGABYTES' => 1024 ** 3,
            'TB', 'TERABYTES' => 1024 ** 4,
            default => throw new InvalidArgumentException("Invalid unit: {$unit}. Supported units: B, KB, MB, GB, TB"),
        };

        return round($this->bytes / $divisor, 2);
    }

    /**
     * Format as a human readable string, e.g. "1.5 KB"
     */
    public function format(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = abs($this->bytes);
        $pow = (int) min($bytes > 0 ? floor(log($bytes) / log(1024)) : 0, count($units) - 1);

        $value = round($bytes / (1024 ** $pow), 2);
        $sign = $this->bytes < 0 ? '-' : '';

        return $sign.$value.' '.$units[$pow];
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
