<?php

namespace Iak\Action\Testing;

use Iak\Action\Action;
use Iak\Action\Testing\Results\Entry;
use Iak\Action\Testing\Results\Profile;
use Iak\Action\Testing\Results\Query;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;

class Testable
{
    public function __construct(
        public Action $action
    ) {}

    /** @var array<class-string> */
    protected array $only = [];

    protected bool $profileMainAction = false;

    /** @var array<class-string> */
    protected array $actionsToBeProfiled = [];

    /** @var array<Profile> */
    protected array $profiledActions = [];

    protected \Closure $profilesCallback;

    protected bool $recordMainActionDbCalls = false;

    /** @var array<class-string> */
    protected array $actionsToRecordDbCalls = [];

    /** @var array<Query> */
    protected array $recordedDbCalls = [];

    protected \Closure $dbCallsCallback;

    protected bool $recordMainActionLogs = false;

    /** @var array<class-string> */
    protected array $actionsToRecordLogs = [];

    /** @var array<Entry> */
    protected array $recordedLogs = [];

    protected \Closure $logsCallback;

    /**
     * @param  string|object|array<class-string|object>  $classes
     */
    public function without(string|object|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        collect($classes)->each(function ($class, $key) {
            [$class, $returnValue] = $this->getClassAndReturnValue($class, $key);

            if ($class instanceof MockInterface || $class instanceof LegacyMockInterface) {
                return $class;
            }

            if (! class_exists($class) && ! app()->bound($class)) {
                throw new \InvalidArgumentException("The class or alias {$class} is not bound to the container");
            }

            $mock = $class::fake()->shouldReceive('handle');
            if ($returnValue) {
                $mock->andReturn($returnValue);
            }

            return $mock;
        });

        return $this;
    }

    /**
     * Alias for {@see without()}.
     *
     * Mocks specific actions, preventing them from executing their real `handle()` method.
     * All other actions execute normally.
     *
     * @param  string|object|array<class-string|object>  $classes
     */
    public function except(string|object|array $classes): static
    {
        return $this->without($classes);
    }

    /**
     * @param  string|array<class-string|object>  $classes
     */
    public function only(string|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        collect($classes)->each(function ($class, $key) {
            if (! class_exists($class) && ! app()->bound($class)) {
                throw new \InvalidArgumentException("The class or alias {$class} is not bound to the container");
            }
        });

        $this->only = $classes;

        return $this;
    }

    /**
     * @param  \Closure|string|array<class-string>  $actions
     */
    public function profile($actions, ?\Closure $callback = null): static
    {
        if (is_null($callback) && is_callable($actions)) {
            $this->profilesCallback = $actions;
            $this->profileMainAction = true;

            return $this;
        }

        if (is_null($callback)) {
            throw new \InvalidArgumentException('A callback is required');
        }

        $this->actionsToBeProfiled = is_array($actions) ? $actions : [$actions];

        foreach ($this->actionsToBeProfiled as $profile) {
            if (! class_exists($profile) && ! app()->bound($profile)) {
                throw new \InvalidArgumentException("The class or alias {$profile} is not bound to the container");
            }
        }

        $this->profilesCallback = $callback;

        return $this;
    }

    /**
     * @param  \Closure|string|array<class-string>  $actions
     */
    public function queries($actions, ?\Closure $callback = null): static
    {
        if (is_null($callback) && is_callable($actions)) {
            $this->dbCallsCallback = $actions;
            $this->actionsToRecordDbCalls = [$this->action::class];
            $this->recordMainActionDbCalls = true;

            return $this;
        }

        if (is_null($callback)) {
            throw new \InvalidArgumentException('A callback is required');
        }

        $this->actionsToRecordDbCalls = is_array($actions) ? $actions : [$actions];

        foreach ($this->actionsToRecordDbCalls as $record) {
            if (! class_exists($record) && ! app()->bound($record)) {
                throw new \InvalidArgumentException("The class or alias {$record} is not bound to the container");
            }
        }

        $this->dbCallsCallback = $callback;

        return $this;
    }

    /**
     * @param  \Closure|string|array<class-string>  $actions
     */
    public function logs($actions, ?\Closure $callback = null): static
    {
        if (is_null($callback) && is_callable($actions)) {
            $this->logsCallback = $actions;
            $this->recordMainActionLogs = true;

            return $this;
        }

        if (is_null($callback)) {
            throw new \InvalidArgumentException('A callback is required');
        }

        $this->actionsToRecordLogs = is_array($actions) ? $actions : [$actions];

        foreach ($this->actionsToRecordLogs as $record) {
            if (! class_exists($record) && ! app()->bound($record)) {
                throw new \InvalidArgumentException("The class or alias {$record} is not bound to the container");
            }
        }

        $this->logsCallback = $callback;

        return $this;
    }

    public function handle(mixed ...$args): mixed
    {
        $this->handleOnly();

        $this->interceptProfiles();
        $this->interceptDatabaseCalls();
        $this->interceptLogs();

        $execute = app(Pipeline::class)
            ->send(function () use ($args) {
                return $this->action->handle(...$args);
            })
            ->through(collect()
                ->when($this->recordMainActionLogs, function (Collection $pipes) {
                    return $pipes->push(function (\Closure $execute, \Closure $next) {
                        // Call next to get the closure wrapped by subsequent pipes
                        $wrapped = $next($execute);

                        // Wrap it with log instrumentation (outermost)
                        return function () use ($wrapped) {
                            $listener = new LogListener($this->action::class);
                            $result = $listener->listen(function () use ($wrapped) {
                                return $wrapped();
                            });
                            $this->addLogs($listener->getLogs());

                            return $result;
                        };
                    });
                })
                ->when($this->recordMainActionDbCalls, function ($pipes) {
                    return $pipes->push(function (\Closure $execute, \Closure $next) {
                        // Call next to get the closure wrapped by subsequent pipes
                        $wrapped = $next($execute);

                        // Wrap it with database query instrumentation (middle)
                        return function () use ($wrapped) {
                            $listener = new QueryListener($this->action::class);
                            $result = $listener->listen(function () use ($wrapped) {
                                return $wrapped();
                            });
                            $this->addQueries($listener->getQueries());

                            return $result;
                        };
                    });
                })
                ->when($this->profileMainAction, function ($pipes) {
                    return $pipes->push(function (\Closure $execute, \Closure $next) {
                        // Call next to get the closure wrapped by subsequent pipes
                        $wrapped = $next($execute);

                        // Wrap it with profile instrumentation (innermost - wraps base)
                        return function () use ($wrapped) {
                            $listener = new ProfileListener($this->action, $this->action);
                            $result = $listener->listen(function () use ($wrapped) {
                                return $wrapped();
                            });
                            $this->addProfile($listener->getProfile());

                            return $result;
                        };
                    });
                })->toArray()
            )
            ->thenReturn();

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
     * @param  array<Query>  $queries
     */
    public function addQueries(array $queries): void
    {
        if (empty($queries)) {
            return;
        }

        $this->recordedDbCalls = array_merge($this->recordedDbCalls, $queries);
    }

    /**
     * @param  array<Entry>  $logs
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
            $this->bindProxyWrapper($actionToBeProfiled, function ($action) use ($actionToBeProfiled) {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createProxyClass($actionToBeProfiled);
                $config = new ProxyConfiguration(
                    fn ($action, $eventSource) => new ProfileListener($action, $eventSource),
                    fn ($testable, $resultData) => $testable->addProfile($resultData),
                    fn ($listener) => $listener->getProfile()
                );

                return new $proxyClass($this, $action, $config);
            });
        }
    }

    protected function interceptDatabaseCalls(): void
    {
        if (empty($this->actionsToRecordDbCalls)) {
            return;
        }

        foreach ($this->actionsToRecordDbCalls as $actionToRecordDbCalls) {
            $this->bindProxyWrapper($actionToRecordDbCalls, function ($action) use ($actionToRecordDbCalls) {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createProxyClass($actionToRecordDbCalls);
                $config = new ProxyConfiguration(
                    fn ($action, $eventSource) => new QueryListener(get_class($action)),
                    fn ($testable, $resultData) => $testable->addQueries($resultData),
                    fn ($listener) => $listener->getQueries()
                );

                return new $proxyClass($this, $action, $config);
            });
        }
    }

    protected function interceptLogs(): void
    {
        if (empty($this->actionsToRecordLogs)) {
            return;
        }

        foreach ($this->actionsToRecordLogs as $actionToRecordLogs) {
            $this->bindProxyWrapper($actionToRecordLogs, function ($action) use ($actionToRecordLogs) {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createProxyClass($actionToRecordLogs);
                $config = new ProxyConfiguration(
                    fn ($action, $eventSource) => new LogListener(get_class($action)),
                    fn ($testable, $resultData) => $testable->addLogs($resultData),
                    fn ($listener) => $listener->getLogs()
                );

                return new $proxyClass($this, $action, $config);
            });
        }
    }

    protected function bindProxyWrapper(string $actionClass, \Closure $wrapper): void
    {
        // Capture the previous resolver if one exists (another feature may have already bound it)
        $previousResolver = null;
        if (app()->bound($actionClass)) {
            $bindings = app()->getBindings();
            if (isset($bindings[$actionClass]['concrete']) && is_callable($bindings[$actionClass]['concrete'])) {
                $previousResolver = $bindings[$actionClass]['concrete'];
            }
        }

        app()->bind($actionClass, function () use ($actionClass, $wrapper, $previousResolver) {
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

            // Wrap whatever we resolved with our proxy
            return $wrapper($resolved);
        });
    }

    protected function createProxyClass(
        string $actionClass,
    ): string {
        // Create a dynamic proxy class that extends the action and uses the proxy trait
        $proxyClass = 'Proxy_'.md5($actionClass.spl_object_id($this));
        $fqcn = '\\'.ltrim($actionClass, '\\');

        if (! class_exists($actionClass)) {
            throw new \InvalidArgumentException("Invalid class: $actionClass");
        }

        // Check if class already exists
        if (class_exists($proxyClass)) {
            return $proxyClass;
        }

        $code = <<<PHP
    final class $proxyClass extends $fqcn 
    {
    use \\Iak\\Action\\Testing\\Traits\\ProxyTrait;
    }
    PHP;
        eval($code);

        return $proxyClass;
    }

    protected function handleOnly(): void
    {
        if (empty($this->only)) {
            return;
        }

        app()->beforeResolving(function ($object) {
            if (! class_exists($object)) {
                return;
            }

            if (! (new \ReflectionClass($object))->isSubclassOf(Action::class)) {
                return;
            }

            if (in_array($object, $this->only)) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
                if (! isset($frame['object']) || $frame['object'] === $this) {
                    continue;
                }

                if (! $frame['object'] instanceof ($this->action::class)) {
                    continue;
                }

                $object::fake()->shouldReceive('handle');
                break;
            }
        });
    }

    /**
     * @param  string|object|array<class-string|object>  $class
     * @return array<string|object|null>
     */
    protected function getClassAndReturnValue(string|object|array $class, string|int $key): array
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
