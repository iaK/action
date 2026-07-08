<?php

namespace Iak\Action;

use Illuminate\Support\Facades\Event;
use UnitEnum;

trait HandlesEvents
{
    /** @var array<int, string> */
    protected array $forwardedEvents = [];

    /** @var array<string, bool> */
    protected array $propagatedTo = [];

    /** The ancestor chain captured by the most recent forwardEvents() call. */
    protected ?PropagationContext $propagationContext = null;

    /**
     * Listen for an event emitted by this object
     *
     * @param  callable(mixed $data): void  $callback
     */
    public function on(string|UnitEnum $event, callable $callback): static
    {
        $event = EventName::normalize($event);

        $this->throwIfEventNotAllowed($event, "Cannot listen for event '{$event}'.");

        Event::listen($this->generateEventName($event), $callback);

        return $this;
    }

    /**
     * Emit an event from this object and, when the event is among the
     * forwarded ones, propagate it to the first ancestor using the trait
     */
    public function event(string|UnitEnum $event, mixed $data): static
    {
        $event = EventName::normalize($event);

        $this->throwIfEventNotAllowed($event, "Cannot emit event '{$event}'.");

        // Fire event locally
        event($this->generateEventName($event), [$data]);

        if (in_array($event, $this->forwardedEvents, true)) {
            $this->propagateToAncestor($event, $data);
        }

        return $this;
    }

    /**
     * Enable forwarding and capture the ancestors it will propagate to.
     *
     * The trait-using ancestors on the call stack are resolved here, once,
     * rather than on every emission: call forwardEvents() from within the
     * scope that should receive the events (calling it again re-captures).
     *
     * @param  array<int, string|UnitEnum>|null  $events  The events to forward; null forwards every event the object declares as allowed.
     */
    public function forwardEvents(?array $events = null): static
    {
        $this->forwardedEvents = $events === null
            ? $this->getAllowedEvents()
            : array_map(EventName::normalize(...), $events);
        $this->propagationContext = PropagationContext::capture($this, $this->usesHandlesEvents(...));

        return $this;
    }

    /**
     * Propagate the event to the nearest captured ancestor that allows it
     */
    protected function propagateToAncestor(string $event, mixed $data): void
    {
        foreach ($this->propagationContext?->ancestors() ?? [] as $reference) {
            $ancestor = $reference->get();

            if ($ancestor === null) {
                continue; // Captured ancestor has been garbage collected
            }

            // Create a unique key for this propagation to prevent circular references
            $propagationKey = spl_object_hash($this).'->'.spl_object_hash($ancestor).':'.$event;

            if (isset($this->propagatedTo[$propagationKey])) {
                continue; // Already propagated this event from this object to this ancestor
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

            break; // only the nearest eligible ancestor
        }
    }

    /**
     * Cache of whether a given class uses the HandlesEvents trait, keyed by class name
     *
     * Static trait properties are duplicated per using class but shared with
     * subclasses via self::, so entries must stay keyed by the runtime class
     * ($object::class) rather than self::class.
     *
     * @var array<class-string, bool>
     */
    protected static array $usesHandlesEventsCache = [];

    /**
     * Determine if the object uses the HandlesEvents trait anywhere in its
     * class hierarchy, including through intermediate traits — class_uses()
     * alone would miss a user trait that composes HandlesEvents.
     */
    protected function usesHandlesEvents(object $object): bool
    {
        $class = $object::class;

        if (isset(self::$usesHandlesEventsCache[$class])) {
            return self::$usesHandlesEventsCache[$class];
        }

        return self::$usesHandlesEventsCache[$class] = in_array(
            HandlesEvents::class, class_uses_recursive($class), true
        );
    }

    /**
     * Cache of resolved allowed events, keyed by class name
     *
     * Storage is shared across the inheritance subtree via self::, so a subclass
     * (or eval'd proxy) without its own #[EmitsEvents] must still be keyed by its
     * own runtime class (static::class) even though it caches its parent's
     * events - otherwise its fallback would overwrite the parent's own entry.
     *
     * @var array<class-string, array<int, string>>
     */
    protected static array $allowedEventsCache = [];

    /**
     * Get allowed events declared via #[EmitsEvents(...)]
     *
     * @return array<int, string>
     */
    public function getAllowedEvents(): array
    {
        $class = static::class;

        if (isset(self::$allowedEventsCache[$class])) {
            return self::$allowedEventsCache[$class];
        }

        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(EmitsEvents::class);

        // If no attributes found, check parent class (for proxy classes)
        if (empty($attributes) && $parent = $reflection->getParentClass()) {
            $attributes = $parent->getAttributes(EmitsEvents::class);
        }

        if (empty($attributes)) {
            return self::$allowedEventsCache[$class] = [];
        }

        return self::$allowedEventsCache[$class] = $attributes[0]->newInstance()->events;
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
