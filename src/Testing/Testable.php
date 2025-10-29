<?php

namespace Iak\Action\Testing;

use Iak\Action\Action;
use Mockery\MockInterface;
use Mockery\LegacyMockInterface;
use Iak\Action\Testing\Measurement;
use Iak\Action\Testing\QueryListener;
use Iak\Action\Testing\RuntimeMeasurer;

class Testable
{
    public function __construct(
        public Action $action
    ) {}

    public array $only = [];
    public array $without = [];

    public array $actionsToBeMeasured = [];
    public array $measuredActions = [];

    public \Closure $measurementsCallback;

    public array $actionsToRecordDbCalls = [];
    public ?QueryListener $queryListener = null;
    public \Closure $dbCallsCallback;
    public bool $recordMainActionDbCalls = false;
    
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
    public function measure($actions, ?\Closure $callback = null) 
    {
        if (is_null($callback) && is_callable($actions)) {
            $this->measurementsCallback = $actions;
            $this->actionsToBeMeasured = [$this->action::class];
            return $this;
        }

        if (is_null($callback)) {
            throw new \InvalidArgumentException('A callback is required');
        }

        $this->actionsToBeMeasured = is_array($actions) ? $actions : [$actions];

        foreach($this->actionsToBeMeasured as $measure) {
            if (!class_exists($measure) && !app()->bound($measure)) {
                throw new \InvalidArgumentException("The class or alias {$measure} is not bound to the container");
            }
        }
        
        $this->measurementsCallback = $callback;

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

    public function handle(...$args)
    {
        $this->handleOnly();
        $this->interceptMeasurements();
        $this->interceptDatabaseCalls();

        if (in_array($this->action::class, $this->actionsToBeMeasured)) {
            $result = $this->measureMainAction($args);
        } elseif ($this->recordMainActionDbCalls) {
            // Record database calls for the main action using the shorthand syntax
            if (!$this->queryListener) {
                $this->queryListener = new QueryListener();
            }
            $result = $this->queryListener->listen(function () use ($args) {
                return $this->action->handle(...$args);
            });
        } else {
            $result = $this->action->handle(...$args);
        }

        if (isset($this->measurementsCallback)) {
            $measurementsCallback = $this->measurementsCallback;

            $measurements = array_map(function ($measurer) {
                return match(get_class($measurer)) {
                    RuntimeMeasurer::class => $measurer->result(),
                    Measurement::class => $measurer,
                    default => throw new \InvalidArgumentException("Invalid measurer class: " . get_class($measurer))
                };
            }, $this->measuredActions);
            
            $measurementsCallback($measurements);
        }

        if (isset($this->dbCallsCallback)) {
            $dbCallsCallback = $this->dbCallsCallback;
            $dbCallsCallback($this->queryListener?->getQueries() ?? []);
        }

        return $result;
    }

    private function interceptMeasurements(): void
    {
        foreach ($this->actionsToBeMeasured as $actionToBeMeasured) {
            // Skip the main action - it's measured differently
            if ($actionToBeMeasured === $this->action::class) {
                continue;
            }

            if (!class_exists($actionToBeMeasured)) {
                throw new \InvalidArgumentException("Invalid measure class: $actionToBeMeasured");
            }
                        
            // Resolve the original action BEFORE binding to avoid infinite loop
            $originalAction = $actionToBeMeasured::make();

            app()->bind($actionToBeMeasured, fn () => 
                 new ($this->createMeasureProxyClass($actionToBeMeasured))($this, $originalAction)
            );
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
            // Skip the main action if using shorthand syntax
            if ($actionToRecordDbCalls === $this->action::class && $this->recordMainActionDbCalls) {
                continue;
            }

            if (!class_exists($actionToRecordDbCalls)) {
                throw new \InvalidArgumentException("Invalid recordDbCalls class: $actionToRecordDbCalls");
            }
                        
            // Resolve the original action BEFORE binding to avoid infinite loop
            $originalAction = $actionToRecordDbCalls::make();

            app()->bind($actionToRecordDbCalls, fn () => 
                 new ($this->createDatabaseProxyClass($actionToRecordDbCalls))($this, $originalAction)
            );
        }
    }

    private function createMeasureProxyClass(string $measure): string
    {
        // Create a dynamic proxy class that extends the action and uses ActionMeasurer
        $proxyClass = 'MeasurementProxy_' . md5($measure . spl_object_id($this));
        $fqcn = '\\' . ltrim($measure, '\\');

        if (!class_exists($measure)) {
            throw new \InvalidArgumentException("Invalid measure class: $measure");
        }

        $code = <<<PHP
    final class $proxyClass extends $fqcn 
    {
    use \\Iak\\Action\\Testing\\Traits\\MeasurementProxyTrait;
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

        $code = <<<PHP
    final class $proxyClass extends $fqcn 
    {
    use \\Iak\\Action\\Testing\\Traits\\DatabaseCallProxyTrait;
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

    private function measureMainAction($args)
    {
        $start = microtime(true);
        $result = $this->action->handle(...$args);
        $end = microtime(true);
        $this->measuredActions[] = new Measurement($this->action::class, $start, $end);

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
