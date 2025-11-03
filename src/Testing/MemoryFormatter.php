<?php

namespace Iak\Action\Testing;

class MemoryFormatter
{
    public function __construct(
        public int $bytes,
    ) {}

    public function convertToUnit(string $unit): int|float
    {
        $unit = strtoupper($unit);
        $isNegative = $this->bytes < 0;
        $bytes = abs($this->bytes);

        switch ($unit) {
            case 'B':
            case 'BYTES':
                return $isNegative ? -$bytes : $bytes;
            case 'KB':
            case 'KILOBYTES':
                $result = $bytes / 1024;

                return $isNegative ? -round($result, 2) : round($result, 2);
            case 'MB':
            case 'MEGABYTES':
                $result = $bytes / (1024 * 1024);

                return $isNegative ? -round($result, 2) : round($result, 2);
            case 'GB':
            case 'GIGABYTES':
                $result = $bytes / (1024 * 1024 * 1024);

                return $isNegative ? -round($result, 2) : round($result, 2);
            case 'TB':
            case 'TERABYTES':
                $result = $bytes / (1024 * 1024 * 1024 * 1024);

                return $isNegative ? -round($result, 2) : round($result, 2);
            default:
                throw new \InvalidArgumentException("Invalid unit: {$unit}. Supported units: B, KB, MB, GB, TB");
        }
    }

    public function formatBytes(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $isNegative = $this->bytes < 0;
        $bytes = abs($this->bytes);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        $sign = $isNegative ? '-' : '';

        return $sign.round($bytes, 2).' '.$units[$pow];
    }
}
