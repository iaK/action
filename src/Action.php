<?php

namespace Iak\Action;

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
