<?php

namespace Iak\Action;

use Illuminate\Support\Facades\Event;

trait HandlesEvents
{
    protected array $forwardedEvents = [];
    protected array $propagatedTo = [];

    /**
     * Listen for an event emitted by this object
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
    public function event(string $event, $data): static
    {
        $this->throwIfEventNotAllowed($event, "Cannot emit event '{$event}'.");
    
        // Fire event locally
        event($this->generateEventName($event), [$data]);

        if (!empty($this->forwardedEvents)) {
            $this->propagateToAncestor($event, $data);
        }
    
        return $this;
    }

    public function forwardEvents(?array $events = null): static
    {
        $this->forwardedEvents = $events ?? $this->getAllowedEvents();

        return $this;
    }
    
    /**
     * Inspect the call stack for the first trait-capable ancestor and propagate the event
     */
    protected function propagateToAncestor(string $event, $data): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

        array_shift($trace);

        foreach ($trace as $frame) {
            if (!isset($frame['object']) || $frame['object'] === $this) {
                continue;
            }
    
            $ancestor = $frame['object'];
            
            // Create a unique key for this propagation to prevent circular references
            $propagationKey = spl_object_hash($this) . '->' . spl_object_hash($ancestor) . ':' . $event;
            
            if (isset($this->propagatedTo[$propagationKey])) {
                continue; // Already propagated this event from this object to this ancestor
            }
            
            $usedTraits = [];

            $currentAncestor = $ancestor;

            do {
                $usedTraits = array_merge($usedTraits, class_uses($currentAncestor));
            } while ($currentAncestor = get_parent_class($currentAncestor));

            if (!in_array(HandlesEvents::class, $usedTraits)) {
                continue;
            }

            // Only propagate if ancestor allows this event
            if (in_array($event, $ancestor->getAllowedEvents())) {
                $this->propagatedTo[$propagationKey] = true;
                $ancestor->event($event, $data);
            }
    
            break; // only first trait-capable ancestor
        }
    }
    
    /**
     * Get allowed events declared via #[EmitsEvents(...)]
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
    
        $instance = $attributes[0]->newInstance();
    
        return $instance->events;
    }
    
    /**
     * Validate that an event is allowed on this object
     */
    protected function throwIfEventNotAllowed(string $event, string $description): void
    {
        if (!in_array($event, $this->getAllowedEvents())) {
            $allowedEvents = $this->getAllowedEvents();
            $suggestion = !empty($allowedEvents) ? " Did you mean: '" . $allowedEvents[0] . "'?" : '';
            throw new \InvalidArgumentException($description . $suggestion);
        }
    }
    
    /**
     * Generate a unique event name for this instance
     */
    protected function generateEventName(string $event): string
    {
        return static::class . '.' . spl_object_hash($this) . '.' . $event;
    }

    public function __destruct()
    {
        // Only clean up if the application is still available
        if (app()->bound('events')) {
            foreach ($this->getAllowedEvents() as $event) {
                Event::forget($this->generateEventName($event));
            }
        }
    }
}
