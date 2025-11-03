<?php

namespace Iak\Action\Testing\Results;

use Iak\Action\Testing\MemoryFormatter;

class Memory
{
    public function __construct(
        public string $name,
        public int $memory,
        public float $timestamp
    ) {}

    public function formattedMemory(?string $unit = null): int|string
    {
        $formatter = new MemoryFormatter($this->memory);

        if ($unit === null) {
            return $formatter->formatBytes();
        }

        return $formatter->convertToUnit($unit);
    }
}
