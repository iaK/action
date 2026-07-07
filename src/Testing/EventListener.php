<?php

namespace Iak\Action\Testing;

use Iak\Action\Action;
use Iak\Action\Testing\Results\EmittedEvent;
use Illuminate\Support\Facades\Event;

class EventListener implements Listener
{
    protected bool $enabled = false;

    /** @var EmittedEvent[] */
    protected array $events = [];

    public function __construct(Action $action, ?Action $eventSource = null)
    {
        // Both the underlying action and, in the proxy path, the proxy
        // instance dispatch instance-scoped events; listen to every allowed
        // event on each by its exact name (never wildcards - event names may
        // themselves contain dots).
        $sources = [$action];

        if ($eventSource !== null && $eventSource !== $action) {
            $sources[] = $eventSource;
        }

        // Records are always attributed to the real action class, not an
        // eval'd proxy class name.
        $actionClass = $action::class;

        // Listen through a weak reference so the dispatcher does not keep
        // this listener alive. No forget() on cleanup: these are the same
        // instance-scoped names user on() listeners live under, so removing
        // them would silently delete user listeners - instead a dropped
        // listener leaves dead no-op closures, and the emitting action's own
        // __destruct forgets every instance-scoped name.
        // The closure must be static - non-static closures bind $this even
        // when they do not use it, which would defeat the weak reference.
        $reference = \WeakReference::create($this);

        foreach ($sources as $source) {
            $prefix = $source::class.'.'.spl_object_hash($source).'.';

            foreach ($source->getAllowedEvents() as $event) {
                Event::listen($prefix.$event, static function (mixed $data) use ($reference, $event, $actionClass): void {
                    $reference->get()?->record($event, $data, $actionClass);
                });
            }
        }
    }

    public function listen(callable $callback): mixed
    {
        $this->enabled = true;

        try {
            return $callback();
        } finally {
            $this->enabled = false;
        }
    }

    /**
     * Append a record for an event dispatched by an instrumented instance.
     * Only records while listen() is running, so construction-to-listen gaps
     * and dispatches after the run are ignored.
     */
    public function record(string $event, mixed $data, string $action): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->events[] = new EmittedEvent($event, $data, $action);
    }

    /**
     * @return EmittedEvent[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }
}
