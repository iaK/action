<?php

namespace Iak\Action;

use Illuminate\Support\Facades\Event;

trait HandlesEvents
{
    /** @var array<int, string> */
    protected array $forwardedEvents = [];

    /** @var array<string, bool> */
    protected array $propagatedTo = [];

    /**
     * Listen for an event emitted by this object
     *
     * @param  callable(mixed $data): void  $callback
     */
    public function on(string $event, callable $callback): static
    {
        $this->throwIfEventNotAllowed($event, "Cannot listen for event '{$event}'.");

        Event::listen($this->generateEventName($event), $callback);

        return $this;
    }

    /**
     * Emit an event from this object and propagate to first ancestor using the trait
     */
    public function event(string $event, mixed $data): static
    {
        $this->throwIfEventNotAllowed($event, "Cannot emit event '{$event}'.");

        // Fire event locally
        event($this->generateEventName($event), [$data]);

        if (! empty($this->forwardedEvents)) {
            $this->propagateToAncestor($event, $data);
        }

        return $this;
    }

    /**
     * @param  array<int, string>|null  $events
     */
    public function forwardEvents(?array $events = null): static
    {
        $this->forwardedEvents = $events ?? $this->getAllowedEvents();

        return $this;
    }

    /**
     * Inspect the call stack for the first trait-capable ancestor and propagate the event
     */
    protected function propagateToAncestor(string $event, mixed $data): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS);

        array_shift($trace);

        foreach ($trace as $frame) {
            $ancestor = $frame['object'] ?? null;

            if ($ancestor === null || $ancestor === $this) {
                continue;
            }

            // Create a unique key for this propagation to prevent circular references
            $propagationKey = spl_object_hash($this).'->'.spl_object_hash($ancestor).':'.$event;

            if (isset($this->propagatedTo[$propagationKey])) {
                continue; // Already propagated this event from this object to this ancestor
            }

            if (! $this->usesHandlesEvents($ancestor)) {
                continue;
            }

            $getAllowedEvents = [$ancestor, 'getAllowedEvents'];
            $emitEvent = [$ancestor, 'event'];

            if (! is_callable($getAllowedEvents) || ! is_callable($emitEvent)) {
                continue;
            }

            // Only propagate if ancestor allows this event
            $allowedEvents = $getAllowedEvents();

            if (is_array($allowedEvents) && in_array($event, $allowedEvents, true)) {
                $this->propagatedTo[$propagationKey] = true;
                $emitEvent($event, $data);
            }

            break; // only first trait-capable ancestor
        }
    }

    /**
     * Cache of whether a given class uses the HandlesEvents trait, keyed by class name
     *
     * @var array<class-string, bool>
     */
    protected static array $usesHandlesEventsCache = [];

    /**
     * Determine if the object uses the HandlesEvents trait anywhere in its class hierarchy
     */
    protected function usesHandlesEvents(object $object): bool
    {
        $class = $object::class;

        if (isset(self::$usesHandlesEventsCache[$class])) {
            return self::$usesHandlesEventsCache[$class];
        }

        $current = $class;
        $uses = false;

        do {
            if (in_array(HandlesEvents::class, class_uses($current) ?: [], true)) {
                $uses = true;
                break;
            }
        } while ($current = get_parent_class($current));

        return self::$usesHandlesEventsCache[$class] = $uses;
    }

    /**
     * Get allowed events declared via #[EmitsEvents(...)]
     *
     * @return array<int, string>
     */
    public function getAllowedEvents(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $attributes = $reflection->getAttributes(EmitsEvents::class);

        // If no attributes found, check parent class (for proxy classes)
        if (empty($attributes) && $parent = $reflection->getParentClass()) {
            $attributes = $parent->getAttributes(EmitsEvents::class);
        }

        if (empty($attributes)) {
            return [];
        }

        return $attributes[0]->newInstance()->events;
    }

    /**
     * Validate that an event is allowed on this object
     */
    protected function throwIfEventNotAllowed(string $event, string $description): void
    {
        if (! in_array($event, $this->getAllowedEvents(), true)) {
            $allowedEvents = $this->getAllowedEvents();
            $suggestion = ! empty($allowedEvents) ? " Did you mean: '".$allowedEvents[0]."'?" : '';
            throw new \InvalidArgumentException($description.$suggestion);
        }
    }

    /**
     * Generate a unique event name for this instance
     */
    protected function generateEventName(string $event): string
    {
        return static::class.'.'.spl_object_hash($this).'.'.$event;
    }

    public function __destruct()
    {
        // Only clean up if this container has a booted event dispatcher. The
        // dispatcher is resolved from the current container rather than the
        // Event facade: during test teardown the facade can still point at a
        // previous, flushed application where resolving 'events' throws.
        $app = app();

        if (! $app->resolved('events')) {
            return;
        }

        $dispatcher = $app->make('events');

        foreach ($this->getAllowedEvents() as $event) {
            $dispatcher->forget($this->generateEventName($event));
        }
    }
}
