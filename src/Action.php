<?php

namespace Iak\Action;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use ReflectionClass;

abstract class Action
{
    use HandlesEvents;

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

    public function within(callable $callback)
    {
        $body = new Testable();
        
        $callback($body);
        
        if (!empty($body->only)) {
            app()->beforeResolving(function ($object, $app) use ($body) {
                if (!class_exists($object)) {
                    return;
                }

                $reflection = new ReflectionClass($object);
                if (!$reflection->isSubclassOf(Action::class)) {
                    return;
                }

                // Mock all actions that are NOT in the only array
                if (!in_array($object, $body->only)) {
                    $object::fake()->shouldReceive('handle');
                }
            });
        }

        return $this;
    }


}
