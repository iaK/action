<?php

namespace Iak\Action\Testing;

use Closure;
use Iak\Action\Action;
use Iak\Action\Testing\Results\Entry;
use Iak\Action\Testing\Results\Profile;
use Iak\Action\Testing\Results\Query;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mockery;
use Mockery\CompositeExpectation;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use RuntimeException;

/**
 * @template TAction of Action
 */
class Testable
{
    /**
     * @param  TAction  $action
     */
    public function __construct(
        public Action $action
    ) {}

    /** @var array<int, class-string<Action>> */
    protected array $only = [];

    protected bool $profileMainAction = false;

    /** @var array<int, class-string<Action>> */
    protected array $actionsToBeProfiled = [];

    /** @var array<int, Profile> */
    protected array $profiledActions = [];

    /** @var Closure(Collection<int, Profile>): void */
    protected Closure $profilesCallback;

    protected bool $recordMainActionDbCalls = false;

    /** @var array<int, class-string<Action>> */
    protected array $actionsToRecordDbCalls = [];

    /** @var array<int, Query> */
    protected array $recordedDbCalls = [];

    /** @var Closure(Collection<int, Query>): void */
    protected Closure $dbCallsCallback;

    protected bool $recordMainActionLogs = false;

    /** @var array<int, class-string<Action>> */
    protected array $actionsToRecordLogs = [];

    /** @var array<int, Entry> */
    protected array $recordedLogs = [];

    /** @var Closure(Collection<int, Entry>): void */
    protected Closure $logsCallback;

    /**
     * Mock specific actions, preventing them from executing their real
     * handle() method. All other actions execute normally.
     *
     * Accepts a class name, a list of class names, a map of class names to
     * mocked return values, or an already prepared Mockery mock.
     *
     * @param  class-string<Action>|MockInterface|LegacyMockInterface|CompositeExpectation|array<class-string<Action>|int, mixed>  $classes
     */
    public function without(string|object|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        foreach ($classes as $key => $class) {
            [$class, $returnValue] = $this->getClassAndReturnValue($class, $key);

            if ($class instanceof CompositeExpectation
                || $class instanceof MockInterface
                || $class instanceof LegacyMockInterface) {
                continue;
            }

            $expectation = $this->resolveActionClass($class)::fake()->shouldReceive('handle');

            if ($returnValue) {
                $expectation->andReturn($returnValue);
            }
        }

        return $this;
    }

    /**
     * Alias for {@see without()}.
     *
     * Mocks specific actions, preventing them from executing their real `handle()` method.
     * All other actions execute normally.
     *
     * @param  class-string<Action>|MockInterface|LegacyMockInterface|CompositeExpectation|array<class-string<Action>|int, mixed>  $classes
     */
    public function except(string|object|array $classes): static
    {
        return $this->without($classes);
    }

    /**
     * Specify which actions should execute normally. All other actions
     * resolved while the action under test runs are mocked automatically.
     *
     * @param  class-string<Action>|array<int, class-string<Action>>  $classes
     */
    public function only(string|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        $this->only = array_map(
            fn (mixed $class): string => $this->resolveActionClass($class),
            $classes
        );

        return $this;
    }

    /**
     * Profile the action under test, or specific nested actions.
     *
     * @param  (Closure(Collection<int, Profile>): void)|class-string<Action>|array<int, class-string<Action>>  $actions
     * @param  (Closure(Collection<int, Profile>): void)|null  $callback
     */
    public function profile(Closure|string|array $actions, ?Closure $callback = null): static
    {
        if (is_null($callback) && $actions instanceof Closure) {
            $this->profilesCallback = $actions;
            $this->profileMainAction = true;

            return $this;
        }

        if (is_null($callback)) {
            throw new InvalidArgumentException('A callback is required');
        }

        $actions = is_array($actions) ? $actions : [$actions];

        $this->actionsToBeProfiled = array_map(
            fn (mixed $action): string => $this->resolveActionClass($action),
            $actions
        );

        $this->profilesCallback = $callback;

        return $this;
    }

    /**
     * Record database queries executed by the action under test, or by
     * specific nested actions.
     *
     * @param  (Closure(Collection<int, Query>): void)|class-string<Action>|array<int, class-string<Action>>  $actions
     * @param  (Closure(Collection<int, Query>): void)|null  $callback
     */
    public function queries(Closure|string|array $actions, ?Closure $callback = null): static
    {
        if (is_null($callback) && $actions instanceof Closure) {
            $this->dbCallsCallback = $actions;
            $this->actionsToRecordDbCalls = [$this->action::class];
            $this->recordMainActionDbCalls = true;

            return $this;
        }

        if (is_null($callback)) {
            throw new InvalidArgumentException('A callback is required');
        }

        $actions = is_array($actions) ? $actions : [$actions];

        $this->actionsToRecordDbCalls = array_map(
            fn (mixed $action): string => $this->resolveActionClass($action),
            $actions
        );

        $this->dbCallsCallback = $callback;

        return $this;
    }

    /**
     * Record log entries written by the action under test, or by specific
     * nested actions.
     *
     * @param  (Closure(Collection<int, Entry>): void)|class-string<Action>|array<int, class-string<Action>>  $actions
     * @param  (Closure(Collection<int, Entry>): void)|null  $callback
     */
    public function logs(Closure|string|array $actions, ?Closure $callback = null): static
    {
        if (is_null($callback) && $actions instanceof Closure) {
            $this->logsCallback = $actions;
            $this->recordMainActionLogs = true;

            return $this;
        }

        if (is_null($callback)) {
            throw new InvalidArgumentException('A callback is required');
        }

        $actions = is_array($actions) ? $actions : [$actions];

        $this->actionsToRecordLogs = array_map(
            fn (mixed $action): string => $this->resolveActionClass($action),
            $actions
        );

        $this->logsCallback = $callback;

        return $this;
    }

    /**
     * Execute the action and run any registered inspection callbacks
     */
    public function handle(mixed ...$args): mixed
    {
        $this->handleOnly();

        $this->interceptProfiles();
        $this->interceptDatabaseCalls();
        $this->interceptLogs();

        $execute = function () use ($args): mixed {
            return $this->action->handle(...$args);
        };

        // Wrap the execution innermost to outermost: profiling measures only
        // the action itself, while query and log recording wrap around it.
        if ($this->profileMainAction) {
            $execute = function () use ($execute): mixed {
                $listener = new ProfileListener($this->action, $this->action);
                $result = $listener->listen($execute);
                $this->addProfile($listener->getProfile());

                return $result;
            };
        }

        if ($this->recordMainActionDbCalls) {
            $execute = function () use ($execute): mixed {
                $listener = new QueryListener($this->action::class);
                $result = $listener->listen($execute);
                $this->addQueries($listener->getQueries());

                return $result;
            };
        }

        if ($this->recordMainActionLogs) {
            $execute = function () use ($execute): mixed {
                $listener = new LogListener($this->action::class);
                $result = $listener->listen($execute);
                $this->addLogs($listener->getLogs());

                return $result;
            };
        }

        $result = $execute();

        if (isset($this->profilesCallback)) {
            ($this->profilesCallback)(collect($this->profiledActions));
        }

        if (isset($this->dbCallsCallback)) {
            ($this->dbCallsCallback)(collect($this->recordedDbCalls));
        }

        if (isset($this->logsCallback)) {
            ($this->logsCallback)(collect($this->recordedLogs));
        }

        return $result;
    }

    /**
     * @param  array<int, Query>  $queries
     */
    public function addQueries(array $queries): void
    {
        if (empty($queries)) {
            return;
        }

        $this->recordedDbCalls = array_merge($this->recordedDbCalls, $queries);
    }

    /**
     * @param  array<int, Entry>  $logs
     */
    public function addLogs(array $logs): void
    {
        if (empty($logs)) {
            return;
        }

        $this->recordedLogs = array_merge($this->recordedLogs, $logs);
    }

    public function addProfile(Profile $profile): void
    {
        $this->profiledActions[] = $profile;
    }

    protected function interceptProfiles(): void
    {
        foreach ($this->actionsToBeProfiled as $actionToBeProfiled) {
            $this->bindProxyWrapper($actionToBeProfiled, function (Action $action) use ($actionToBeProfiled): object {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createProxyClass($actionToBeProfiled);
                $config = new ProxyConfiguration(
                    fn (Action $action, Action $eventSource) => new ProfileListener($action, $eventSource),
                    fn (Testable $testable, Profile $profile) => $testable->addProfile($profile),
                    fn (ProfileListener $listener) => $listener->getProfile()
                );

                return new $proxyClass($this, $action, $config);
            });
        }
    }

    protected function interceptDatabaseCalls(): void
    {
        foreach ($this->actionsToRecordDbCalls as $actionToRecordDbCalls) {
            $this->bindProxyWrapper($actionToRecordDbCalls, function (Action $action) use ($actionToRecordDbCalls): object {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createProxyClass($actionToRecordDbCalls);
                $config = new ProxyConfiguration(
                    fn (Action $action, Action $eventSource) => new QueryListener($action::class),
                    fn (Testable $testable, array $queries) => $testable->addQueries($queries),
                    fn (QueryListener $listener) => $listener->getQueries()
                );

                return new $proxyClass($this, $action, $config);
            });
        }
    }

    protected function interceptLogs(): void
    {
        foreach ($this->actionsToRecordLogs as $actionToRecordLogs) {
            $this->bindProxyWrapper($actionToRecordLogs, function (Action $action) use ($actionToRecordLogs): object {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createProxyClass($actionToRecordLogs);
                $config = new ProxyConfiguration(
                    fn (Action $action, Action $eventSource) => new LogListener($action::class),
                    fn (Testable $testable, array $logs) => $testable->addLogs($logs),
                    fn (LogListener $listener) => $listener->getLogs()
                );

                return new $proxyClass($this, $action, $config);
            });
        }
    }

    /**
     * @param  class-string<Action>  $actionClass
     * @param  Closure(Action): object  $wrapper
     */
    protected function bindProxyWrapper(string $actionClass, Closure $wrapper): void
    {
        // Capture the previous resolver if one exists (another feature may have already bound it)
        $previousResolver = null;

        if (app()->bound($actionClass)) {
            $concrete = app()->getBindings()[$actionClass]['concrete'] ?? null;

            if ($concrete instanceof Closure) {
                $previousResolver = $concrete;
            }
        }

        app()->bind($actionClass, function () use ($actionClass, $wrapper, $previousResolver): object {
            // If there's a previous resolver (another feature), use it to resolve
            // Otherwise, resolve the original action from the container
            if ($previousResolver) {
                $resolved = $previousResolver();
            } else {
                // Temporarily unbind to get the original action
                app()->offsetUnset($actionClass);
                $resolved = $actionClass::make();
                // Rebind for future resolutions
                $this->bindProxyWrapper($actionClass, $wrapper);
            }

            // Wrap whatever we resolved with our proxy. Anything that is not
            // an action (e.g. a bound mock) is passed through untouched.
            if ($resolved instanceof Action) {
                return $wrapper($resolved);
            }

            if (is_object($resolved)) {
                return $resolved;
            }

            throw new RuntimeException("Unable to resolve {$actionClass} from the container");
        });
    }

    /**
     * @return class-string
     */
    protected function createProxyClass(string $actionClass): string
    {
        // Create a dynamic proxy class that extends the action and uses the proxy trait
        $proxyClass = 'Proxy_'.md5($actionClass.spl_object_id($this));
        $fqcn = '\\'.ltrim($actionClass, '\\');

        if (! class_exists($actionClass)) {
            throw new InvalidArgumentException("Invalid class: $actionClass");
        }

        // Check if class already exists
        if (! class_exists($proxyClass)) {
            eval(<<<PHP
                final class $proxyClass extends $fqcn
                {
                    use \\Iak\\Action\\Testing\\Traits\\ProxyTrait;
                }
            PHP);
        }

        return $this->ensureClassExists($proxyClass);
    }

    /**
     * Assert that a class exists, e.g. after being defined dynamically
     *
     * @return class-string
     */
    protected function ensureClassExists(string $class): string
    {
        if (! class_exists($class)) {
            throw new RuntimeException("Failed to create proxy class {$class}");
        }

        return $class;
    }

    protected function handleOnly(): void
    {
        if (empty($this->only)) {
            return;
        }

        app()->beforeResolving(function (mixed $abstract): void {
            if (! is_string($abstract) || ! class_exists($abstract)) {
                return;
            }

            if (! is_subclass_of($abstract, Action::class)) {
                return;
            }

            if (in_array($abstract, $this->only, true)) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
                if (! isset($frame['object']) || $frame['object'] === $this) {
                    continue;
                }

                if (! $frame['object'] instanceof ($this->action::class)) {
                    continue;
                }

                // $abstract::fake()->shouldReceive('handle');
                $mockName = 'Mock_'.md5($abstract.spl_object_id($this));
                eval(<<<PHP
                    class $mockName extends $abstract {
                        public function handle(...\$args) {}
                    };
                PHP);

                $mock = Mockery::mock($mockName);
                $mock->shouldReceive('handle')->withAnyArgs()->andReturn(null);

                app()->offsetSet($abstract, $mock);

                break;
            }
        });
    }

    /**
     * Validate that a `without()`/`only()` entry is an action class name
     *
     * @return class-string<Action>
     */
    protected function resolveActionClass(mixed $class): string
    {
        if (! is_string($class) || ! is_subclass_of($class, Action::class)) {
            $name = is_string($class) ? $class : get_debug_type($class);

            throw new InvalidArgumentException("The class or alias {$name} is not bound to the container");
        }

        return $class;
    }

    /**
     * Split a `without()` entry into the target class or mock and its
     * optional mocked return value
     *
     * @return array{mixed, mixed}
     */
    protected function getClassAndReturnValue(mixed $class, string|int $key): array
    {
        if (is_string($key)) {
            return [$key, $class];
        }

        if (is_array($class)) {
            return [array_key_first($class), array_values($class)[0]];
        }

        return [$class, null];
    }
}
