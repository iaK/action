<?php

namespace Iak\Action;

use Illuminate\Support\Facades\Event;
use Mockery;
use Iak\Action\Testing\Testable;
use Mockery\MockInterface;
use Mockery\LegacyMockInterface;

/**
 * @method mixed handle(...$args)
 */
abstract class Action
{
    use HandlesEvents;

    /**
     * Record memory usage at a specific point in the action
     */
    public function recordMemory(string $name): void
    {
        // Dispatch an instance-scoped Laravel event so any profiler
        // attached to this specific instance can record the memory point.
        $eventName = 'action.record_memory.' . spl_object_hash($this);
        Event::dispatch($eventName, [$name]);
    }

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
     * Create a testable instance of the action
     */
    public static function test(?callable $callback = null): Testable
    {
        $action = static::make();
        $testable = new Testable($action);
                
        if (isset($callback)) {
            $callback($testable);
        }

        return $testable;
    }
}
