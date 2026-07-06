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

    /** @var array<int, string> */
    protected array $listeningTo = [];

    public function __construct(private Action $action, ?Action $eventSource = null)
    {
        $this->eventSource = $eventSource;

        // Always listen to the underlying action instance
        $this->listeningTo[] = 'action.record_memory.'.spl_object_hash($action);

        // If a different event source (e.g., proxy) is provided, listen to it as well
        if ($eventSource !== null && $eventSource !== $action) {
            $this->listeningTo[] = 'action.record_memory.'.spl_object_hash($eventSource);
        }

        // Listen through a weak reference so the dispatcher does not keep
        // this profiler alive: a profiler that is dropped without running
        // must be collectable so its destructor can remove these listeners.
        // The closure must be static - non-static closures bind $this even
        // when they do not use it, which would defeat the weak reference.
        $reference = \WeakReference::create($this);

        foreach ($this->listeningTo as $event) {
            Event::listen($event, static function (string $name) use ($reference) {
                $reference->get()?->recordMemory($name);
            });
        }
    }

    public function listen(callable $callback): mixed
    {
        $this->startMemory = memory_get_usage(true);
        $this->start = microtime(true);

        try {
            $result = $callback();

            // Stop measuring before any cleanup so the profile only covers
            // the callback itself
            $this->end = microtime(true);
            $this->endMemory = memory_get_usage(true);
            $this->peakMemory = memory_get_peak_usage(true);

            return $result;
        } finally {
            // Remove the record_memory listeners once the profiled run is
            // over: spl_object_hash values are recycled, so stale listeners
            // could otherwise fire for unrelated objects
            $this->removeListeners();
        }
    }

    protected function removeListeners(): void
    {
        // Resolve the dispatcher from the container rather than the facade:
        // when called from the destructor during test teardown, the facade
        // may point at a previous, flushed application
        $dispatcher = app()->make('events');

        foreach ($this->listeningTo as $event) {
            $dispatcher->forget($event);
        }

        $this->listeningTo = [];
    }

    public function __destruct()
    {
        // Clean up after profilers that were constructed but never ran
        if (! empty($this->listeningTo) && app()->resolved('events')) {
            $this->removeListeners();
        }
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
