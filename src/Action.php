<?php

namespace Iak\Action;

use Iak\Action\Testing\Testable;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;

/**
 * @method mixed handle(mixed ...$args) Execute the action with the given arguments. This method must be implemented by concrete action classes.
 */
abstract class Action
{
    use HandlesEvents;

    /**
     * When true, the mock-binding test helpers are allowed to run outside
     * test/local environments. Toggled explicitly via allowTestHelpers().
     */
    protected static bool $allowTestHelpers = false;

    /**
     * Opt the mock-binding test helpers (fake(), only(), without(), except())
     * into running outside test/local environments.
     *
     * The flag is process-wide; pass false to restore the guarded default.
     */
    public static function allowTestHelpers(bool $allow = true): void
    {
        static::$allowTestHelpers = $allow;
    }

    /**
     * Fail loudly when a mock-binding helper is used outside a test context.
     *
     * These helpers replace real actions with Mockery mocks in the container;
     * a forgotten call in staging/production (worst of all under Octane, where
     * the swap outlives the request) would silently return fake data. The
     * guard turns that fail-silent-wrong into a fail-loud with instructions.
     *
     * @internal Called by Action::fake() and the Testable mock helpers.
     */
    public static function guardTestHelpers(string $helper): void
    {
        if (static::$allowTestHelpers) {
            return;
        }

        // Resolve the app from the container (matching the container-access
        // style used elsewhere) rather than a facade.
        $app = app();

        if ($app->runningUnitTests() || $app->environment('local', 'testing')) {
            return;
        }

        throw new \RuntimeException(
            "{$helper} is a testing-only helper: it binds Mockery mocks into the container "
            ."and cannot run in the [{$app->environment()}] environment, where it would silently "
            .'replace real actions with mocks. Call it only from your test suite, or opt in '
            .'explicitly with Action::allowTestHelpers().'
        );
    }

    /**
     * Record memory usage at a specific point in the action
     */
    public function recordMemory(string $name): void
    {
        // Dispatch an instance-scoped Laravel event so any profiler
        // attached to this specific instance can record the memory point.
        $eventName = 'action.record_memory.'.spl_object_hash($this);
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
     *
     * @return MockInterface&static
     */
    public static function fake(?string $alias = null): MockInterface|LegacyMockInterface
    {
        static::guardTestHelpers('Action::fake()');

        // https://stackoverflow.com/questions/76101686/mocking-static-method-in-same-class-mockery-laravel9
        $mock = Mockery::mock(static::class);

        if (! $mock instanceof static) {
            throw new \RuntimeException('Failed to create a mock of '.static::class);
        }

        app()->offsetSet($alias ?? static::class, $mock);

        return $mock;
    }

    /**
     * Create a testable instance of the action
     *
     * @param  (callable(Testable<static>): void)|null  $callback
     * @return Testable<static>
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
