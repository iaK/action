<?php

namespace Iak\Action\Testing\Results;

use Carbon\CarbonInterval;

class Profile
{
    public function __construct(
        public $class,
        public float $start,
        public float $end,
        public int $startMemory = 0,
        public int $endMemory = 0,
        public int $peakMemory = 0,
        public array $memoryRecords = []
    ) {}

    public function duration(): CarbonInterval
    {
        return CarbonInterval::microseconds(($this->end - $this->start) * 1_000_000);
    }

    public function memoryUsed(?string $unit = null): int|string
    {
        $bytes = $this->endMemory - $this->startMemory;
        
        if ($unit === null) {
            return $this->formatBytes($bytes);
        }
        
        return $this->convertToUnit($bytes, $unit);
    }

    public function startMemory(?string $unit = null): int|string
    {
        if ($unit === null) {
            return $this->formatBytes($this->startMemory);
        }
        
        return $this->convertToUnit($this->startMemory, $unit);
    }

    public function endMemory(?string $unit = null): int|string
    {
        if ($unit === null) {
            return $this->formatBytes($this->endMemory);
        }
        
        return $this->convertToUnit($this->endMemory, $unit);
    }

    public function peakMemory(?string $unit = null): int|string
    {
        if ($unit === null) {
            return $this->formatBytes($this->peakMemory);
        }
        
        return $this->convertToUnit($this->peakMemory, $unit);
    }

    public function records(): array
    {
        return array_map(function ($record) {
            return [
                'name' => $record['name'],
                'memory' => $record['memory'],
                'memory_formatted' => $this->formatBytes($record['memory']),
                'timestamp' => $record['timestamp'],
                'relative_time' => $record['timestamp'] - $this->start
            ];
        }, $this->memoryRecords);
    }

    private function convertToUnit(int $bytes, string $unit): int|string
    {
        $unit = strtoupper($unit);
        $isNegative = $bytes < 0;
        $bytes = abs($bytes);
        
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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $isNegative = $bytes < 0;
        $bytes = abs($bytes);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        $sign = $isNegative ? '-' : '';
        return $sign . round($bytes, 2) . ' ' . $units[$pow];
    }

    public function __toString(): string
    {
        $memoryInfo = $this->startMemory > 0 || $this->endMemory > 0 || $this->peakMemory > 0
            ? " (memory: {$this->memoryUsed()}, peak: {$this->peakMemory()})"
            : '';
            
        return "{$this->class} took {$this->duration()->totalMilliseconds}ms{$memoryInfo}";
    }
}

