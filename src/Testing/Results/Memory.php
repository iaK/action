<?php

namespace Iak\Action\Testing\Results;

class Memory
{
    public function __construct(
        public string $name,
        public int $memory,
        public float $timestamp
    ) {}

    public function size(): MemorySize
    {
        return new MemorySize($this->memory);
    }
}
