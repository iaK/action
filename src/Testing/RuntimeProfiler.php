<?php

namespace Iak\Action\Testing;

use Iak\Action\Action;
use Iak\Action\Testing\Results\Profile;
use Illuminate\Support\Facades\Event;

class RuntimeProfiler
{
    protected string $start;
    protected string $end;
    protected int $startMemory;
    protected int $endMemory;
    protected int $peakMemory;
    protected array $memoryRecords = [];

    public function __construct(private Action $action, ?Action $eventSource = null)
    {
        // Always listen to the underlying action instance
        $underlyingEvent = 'action.record_memory.' . spl_object_hash($action);
        Event::listen($underlyingEvent, function (string $name) {
            $this->recordMemory($name);
        });

        // If a different event source (e.g., proxy) is provided, listen to it as well
        if ($eventSource !== null && $eventSource !== $action) {
            $proxyEvent = 'action.record_memory.' . spl_object_hash($eventSource);
            Event::listen($proxyEvent, function (string $name) {
                $this->recordMemory($name);
            });
        }
    }

    public function handle(...$arguments)
    {
        $this->startMemory = memory_get_usage(true);
        $this->start = microtime(true);
        
        $result = $this->action->handle(...$arguments);
        
        $this->end = microtime(true);
        $this->endMemory = memory_get_usage(true);
        $this->peakMemory = memory_get_peak_usage(true);

        return $result;
    }

    public function recordMemory(string $name): void
    {
        $this->memoryRecords[] = [
            'name' => $name,
            'memory' => memory_get_usage(true),
            'timestamp' => microtime(true)
        ];
    }

    public function result(): Profile
    {
        if (!isset($this->end)) {
            throw new \Exception('Action has not been executed yet');
        }

        return new Profile(
            $this->action::class, 
            $this->start, 
            $this->end,
            $this->startMemory,
            $this->endMemory,
            $this->peakMemory,
            $this->memoryRecords
        );
    }

    public function __call($name, $arguments)
    {
        return $this->action->$name(...$arguments);
    }
}

