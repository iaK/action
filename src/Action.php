<?php

namespace Iak\Action;

use Mockery;
use ReflectionClass;
use Mockery\MockInterface;
use Illuminate\Support\Str;
use Iak\Action\EmitsEvents;
use Mockery\LegacyMockInterface;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

abstract class Action
{
    /**
     * Create a new instance of the action
     */
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Create a mock instance of the action for testing
     */
    public static function fake(?string $alias = null): MockInterface|LegacyMockInterface
    {
        // https://stackoverflow.com/questions/76101686/mocking-static-method-in-same-class-mockery-laravel9
        $mock = Mockery::mock(static::class);
        app()->offsetSet($alias ?? static::class, $mock);

        return $mock;
    }

    /**
     * Listen for an event emitted by this action
     */
    public function on(string $event, callable $callback): static
    {        
        $this->throwIfEventNotAllowed($event, "Cannot listen for event '{$event}'.");

        Event::listen($this->generateEventName($event), $callback);

        return $this;
    }

    /**
     * Emit an event from this action
     */
    public function event(string $event, $data): static
    {
        $this->throwIfEventNotAllowed($event, "Cannot emit event '{$event}'.");
        
        event($this->generateEventName($event), [$data]);

        return $this;
    }

    /**
     * Get all allowed events for this action
     */
    private function getAllowedEvents(): array
    {
        $reflection = new ReflectionClass(static::class);
        $attributes = $reflection->getAttributes(EmitsEvents::class);
        
        if (empty($attributes)) {
            return [];
        }
        
        $emitsEventsAttribute = $attributes[0]->newInstance();
        return $emitsEventsAttribute->events;
    }

    /**
     * Validate that an event is allowed for this action
     */
    private function throwIfEventNotAllowed(string $event, string $description): void
    {
        $allowedEvents = $this->getAllowedEvents();

        if (in_array($event, $allowedEvents)) {
            return;
        }

        $closest = collect($allowedEvents)
            ->map(fn ($allowedEvent) => [
                'option' => $allowedEvent,
                'distance' => levenshtein($event, $allowedEvent),
            ])
            ->sortBy('distance')
            ->filter(fn ($event) => $event['distance'] <= 3)
            ->map(fn ($event) => $event['option'])
            ->first();

        $message = Str::of($description)
            ->when($closest, fn($str) => $str->append(" Did you mean: '{$closest}'?"))
            ->when(!$closest, fn($str) => $str->append(" Allowed events: " . implode(', ', $allowedEvents) . "."));

        throw new InvalidArgumentException($message->toString());
    }

    private function generateEventName(string $event): string
    {
        return static::class . '.' . spl_object_hash($this) . '.' . $event;
    }

    public function __destruct()
    {
        foreach ($this->getAllowedEvents() as $event) {
            Event::forget($this->generateEventName($event));
        }
    }
}
