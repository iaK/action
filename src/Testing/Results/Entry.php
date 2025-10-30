<?php

namespace Iak\Action\Testing\Results;

use Carbon\Carbon;

class Entry
{
    public function __construct(
        public string $level,
        public string $message,
        public array $context,
        public Carbon $timestamp,
        public string $channel = 'default'
    ) {}

    public function __toString(): string
    {
        return "[{$this->timestamp->toDateTimeString()}] {$this->channel}.{$this->level}: {$this->message}" . 
               (empty($this->context) ? '' : ' ' . json_encode($this->context));
    }
}
