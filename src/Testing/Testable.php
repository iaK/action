<?php

namespace Iak\Action\Testing;

use Iak\Action\Action;
use Mockery\MockInterface;
use Mockery\LegacyMockInterface;
use Iak\Action\Testing\Results\Profile;
use Iak\Action\Testing\QueryListener;
use Iak\Action\Testing\LogListener;
use Iak\Action\Testing\RuntimeProfiler;

class Testable
{
    public function __construct(
        public Action $action
    ) {}

    public array $only = [];
    public array $without = [];

    public array $actionsToBeProfiled = [];
    public array $profiledActions = [];
    public bool $profileSelf = false;
    public \Closure $profilesCallback;

    public array $actionsToRecordDbCalls = [];
    public ?QueryListener $queryListener = null;
    public \Closure $dbCallsCallback;
    public bool $recordMainActionDbCalls = false;
    
    public array $actionsToRecordLogs = [];
    public ?LogListener $logListener = null;
    public \Closure $logsCallback;
    public bool $recordMainActionLogs = false;
    
    public function without(string|object|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        collect($classes)->map(function ($class, $key) {
            [$class, $returnValue] = $this->getClassAndReturnValue($class, $key);

            if (!class_exists($class) && !app()->bound($class)) {
                throw new \InvalidArgumentException("The class or alias {$class} is not bound to the container");
            }
            
            if (is_string($class)) {
                $mock = $class::fake()->shouldReceive('handle');
                if ($returnValue) {
                    $mock->andReturn($returnValue);
                }

                return $mock;
            }

            if ($class instanceof MockInterface || $class instanceof LegacyMockInterface) {
                return $class;
            }

            throw new \InvalidArgumentException('Invalid class passed to within');
        });

        return $this;
    }

    public function only(string|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        $this->only = $classes;

        return $this;
    }

    /**
     * @param  \Closure|string|array  $actions
     * @param  ?\Closure|null  $callback
     */
    public function profile($actions, ?\Closure $callback = null) 
    {
        if (is_null($callback) && is_callable($actions)) {
            $this->profilesCallback = $actions;
            $this->profileSelf = true;
            return $this;
        }

        if (is_null($callback)) {
            throw new \InvalidArgumentException('A callback is required');
        }

        $this->actionsToBeProfiled = is_array($actions) ? $actions : [$actions];

        foreach($this->actionsToBeProfiled as $profile) {
            if (!class_exists($profile) && !app()->bound($profile)) {
                throw new \InvalidArgumentException("The class or alias {$profile} is not bound to the container");
            }
        }
        
        $this->profilesCallback = $callback;

        return $this;
    }

    /**
     * @param  \Closure|string|array  $actions
     * @param  ?\Closure|null  $callback
     */
    public function queries($actions, ?\Closure $callback = null) 
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

        foreach($this->actionsToRecordDbCalls as $record) {
            if (!class_exists($record) && !app()->bound($record)) {
                throw new \InvalidArgumentException("The class or alias {$record} is not bound to the container");
            }
        }
        
        $this->dbCallsCallback = $callback;

        return $this;
    }

    /**
     * @param  \Closure|string|array  $actions
     * @param  ?\Closure|null  $callback
     */
    public function logs($actions, ?\Closure $callback = null) 
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

        foreach($this->actionsToRecordLogs as $record) {
            if (!class_exists($record) && !app()->bound($record)) {
                throw new \InvalidArgumentException("The class or alias {$record} is not bound to the container");
            }
        }
        
        $this->logsCallback = $callback;

        return $this;
    }

    public function handle(...$args)
    {
        $this->handleOnly();
        $this->interceptProfiles();
        $this->interceptDatabaseCalls();
        $this->interceptLogs();

        // Build an executable pipeline so logs, queries, and profile can be combined
        $execute = function () use ($args) {
            return $this->action->handle(...$args);
        };

        $profiler = null;

        // Profile layer
        if ($this->profileSelf) {
            $profiler = new RuntimeProfiler($this->action, $this->action);
            $execute = function () use ($profiler, $args) {
                return $profiler->handle(...$args);
            };
        }

        // Database queries layer
        if ($this->recordMainActionDbCalls) {
            if (!$this->queryListener) {
                $this->queryListener = new QueryListener();
            }
            $previous = $execute;
            $execute = function () use ($previous) {
                return $this->queryListener->listen(function () use ($previous) {
                    return $previous();
                });
            };
        }

        // Logs layer
        if ($this->recordMainActionLogs) {
            if (!$this->logListener) {
                $this->logListener = new LogListener();
            }
            $previous = $execute;
            $execute = function () use ($previous) {
                return $this->logListener->listen(function () use ($previous) {
                    return $previous();
                });
            };
        }

        $result = $execute();

        if ($profiler !== null) {
            $this->profiledActions[] = $profiler;
        }

        if (isset($this->profilesCallback)) {
            $profilesCallback = $this->profilesCallback;

            $profiles = array_map(function ($profiler) {
                return match(get_class($profiler)) {
                    RuntimeProfiler::class => $profiler->result(),
                    Profile::class => $profiler,
                    default => throw new \InvalidArgumentException("Invalid profiler class: " . get_class($profiler))
                };
            }, $this->profiledActions);
            
            $profilesCallback($profiles);
        }

        if (isset($this->dbCallsCallback)) {
            $dbCallsCallback = $this->dbCallsCallback;
            $dbCallsCallback($this->queryListener?->getQueries() ?? []);
        }

        if (isset($this->logsCallback)) {
            $logsCallback = $this->logsCallback;
            $logsCallback($this->logListener?->getLogs() ?? []);
        }

        return $result;
    }

    private function interceptProfiles(): void
    {
        foreach ($this->actionsToBeProfiled as $actionToBeProfiled) {
            if (!class_exists($actionToBeProfiled)) {
                throw new \InvalidArgumentException("Invalid profile class: $actionToBeProfiled");
            }
                        
            $this->bindProxyWrapper($actionToBeProfiled, function ($action) use ($actionToBeProfiled) {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createProfileProxyClass($actionToBeProfiled);
                return new $proxyClass($this, $action);
            });
        }
    }

    private function interceptDatabaseCalls(): void
    {
        if (empty($this->actionsToRecordDbCalls)) {
            return;
        }

        // Set up database query logging
        $this->queryListener = new QueryListener();

        foreach ($this->actionsToRecordDbCalls as $actionToRecordDbCalls) {
            if (!class_exists($actionToRecordDbCalls)) {
                throw new \InvalidArgumentException("Invalid recordDbCalls class: $actionToRecordDbCalls");
            }
                        
            $this->bindProxyWrapper($actionToRecordDbCalls, function ($action) use ($actionToRecordDbCalls) {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createDatabaseProxyClass($actionToRecordDbCalls);
                return new $proxyClass($this, $action);
            });
        }
    }

    private function interceptLogs(): void
    {
        if (empty($this->actionsToRecordLogs)) {
            return;
        }

        // Set up log capturing
        $this->logListener = new LogListener();

        foreach ($this->actionsToRecordLogs as $actionToRecordLogs) {
            if (!class_exists($actionToRecordLogs)) {
                throw new \InvalidArgumentException("Invalid recordLogs class: $actionToRecordLogs");
            }
                        
            $this->bindProxyWrapper($actionToRecordLogs, function ($action) use ($actionToRecordLogs) {
                // Use the original action class (the resolved action might already be a proxy)
                $proxyClass = $this->createLogProxyClass($actionToRecordLogs);
                return new $proxyClass($this, $action);
            });
        }
    }

    private function bindProxyWrapper(string $actionClass, \Closure $wrapper): void
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

    private function createProfileProxyClass(string $profile): string
    {
        // Create a dynamic proxy class that extends the action and uses RuntimeProfiler
        $proxyClass = 'ProfileProxy_' . md5($profile . spl_object_id($this));
        $fqcn = '\\' . ltrim($profile, '\\');

        if (!class_exists($profile)) {
            throw new \InvalidArgumentException("Invalid profile class: $profile");
        }

        // Check if class already exists
        if (class_exists($proxyClass)) {
            return $proxyClass;
        }

        $code = <<<PHP
    final class $proxyClass extends $fqcn 
    {
    use \\Iak\\Action\\Testing\\Traits\\ProfileProxyTrait;
    }
    PHP;
        eval($code);

        return $proxyClass;
    }

    private function createDatabaseProxyClass(string $actionToRecord): string
    {
        // Create a dynamic proxy class that extends the action and records database calls
        $proxyClass = 'DatabaseProxy_' . md5($actionToRecord . spl_object_id($this));
        $fqcn = '\\' . ltrim($actionToRecord, '\\');

        if (!class_exists($actionToRecord)) {
            throw new \InvalidArgumentException("Invalid recordDbCalls class: $actionToRecord");
        }

        // Check if class already exists
        if (class_exists($proxyClass)) {
            return $proxyClass;
        }

        $code = <<<PHP
    final class $proxyClass extends $fqcn 
    {
    use \\Iak\\Action\\Testing\\Traits\\DatabaseCallProxyTrait;
    }
    PHP;
        eval($code);

        return $proxyClass;
    }

    private function createLogProxyClass(string $actionToRecord): string
    {
        // Create a dynamic proxy class that extends the action and records logs
        $proxyClass = 'LogProxy_' . md5($actionToRecord . spl_object_id($this));
        $fqcn = '\\' . ltrim($actionToRecord, '\\');

        if (!class_exists($actionToRecord)) {
            throw new \InvalidArgumentException("Invalid recordLogs class: $actionToRecord");
        }

        // Check if class already exists
        if (class_exists($proxyClass)) {
            return $proxyClass;
        }

        $code = <<<PHP
    final class $proxyClass extends $fqcn 
    {
    use \\Iak\\Action\\Testing\\Traits\\LogProxyTrait;
    }
    PHP;
        eval($code);

        return $proxyClass;
    }


    private function handleOnly(): void
    {
        if (empty($this->only)) {
            return;
        }

        app()->beforeResolving(function ($object) {
            if (!class_exists($object)) {
                return;
            }

            if (!(new \ReflectionClass($object))->isSubclassOf(Action::class)) {
                return;
            }

            if (in_array($object, $this->only)) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
                if (!isset($frame['object']) || $frame['object'] === $this) {
                    continue;
                }

                if (!$frame['object'] instanceof ($this->action::class)) {
                    continue;
                }
        
                $object::fake()->shouldReceive('handle');
                break;
            }
        });
    }

    private function profileMainAction($args)
    {
        // Use RuntimeProfiler so memory events on the main action are captured
        $profiler = new RuntimeProfiler($this->action, $this->action);
        $result = $profiler->handle(...$args);
        $this->profiledActions[] = $profiler;

        return $result;
    }

    private function getClassAndReturnValue($class, $key): array
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
