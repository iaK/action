<?php

namespace Iak\Action\Testing;

use Iak\Action\Action;
use Iak\Action\Testing\Results\Memory;
use Iak\Action\Testing\Results\Profile;
use Illuminate\Support\Facades\Event;

class ProfileListener implements Listener
{
    protected float $start;

    protected float $end;

    protected int $startMemory;

    protected int $endMemory;

    protected int $peakMemory;

    /** @var Memory[] */
    protected array $memoryRecords = [];

    protected ?Action $eventSource = null;

    public function __construct(private Action $action, ?Action $eventSource = null)
    {
        $this->eventSource = $eventSource;

        // Always listen to the underlying action instance
        $underlyingEvent = 'action.record_memory.'.spl_object_hash($action);
        Event::listen($underlyingEvent, function (string $name) {
            $this->recordMemory($name);
        });

        // If a different event source (e.g., proxy) is provided, listen to it as well
        if ($eventSource !== null && $eventSource !== $action) {
            $proxyEvent = 'action.record_memory.'.spl_object_hash($eventSource);
            Event::listen($proxyEvent, function (string $name) {
                $this->recordMemory($name);
            });
        }
    }

    public function listen(callable $callback): mixed
    {
        $this->startMemory = memory_get_usage(true);
        $this->start = microtime(true);

        $result = $callback();

        $this->end = microtime(true);
        $this->endMemory = memory_get_usage(true);
        $this->peakMemory = memory_get_peak_usage(true);

        return $result;
    }

    public function handle(mixed ...$arguments): mixed
    {
        return $this->listen(function () use ($arguments) {
            return $this->action->handle(...$arguments);
        });
    }

    public function recordMemory(string $name): void
    {
        $this->memoryRecords[] = new Memory(
            name: $name,
            memory: memory_get_usage(true),
            timestamp: microtime(true)
        );
    }

    public function getProfile(): Profile
    {
        if (! isset($this->end)) {
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
}
