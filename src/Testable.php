<?php

namespace Iak\Action;

use Mockery\MockInterface;
use Mockery\LegacyMockInterface;

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
    
    public function without(string|object|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        collect($classes)->map(function ($class) {
            if (!class_exists($class) && !app()->bound($class)) {
                throw new \InvalidArgumentException("The class or alias {$class} is not bound to the container");
            }
            
            if (is_string($class)) {
                return $class::fake()->shouldReceive('handle');
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

    public function handle(...$args)
    {
        $this->handleOnly();
        $this->interceptMeasurements();


        $result = in_array($this->action::class, $this->actionsToBeMeasured)
            ? $this->measureMainAction($args)
            : $this->action->handle(...$args);

        if (isset($this->measurementsCallback)) {
            $measurementsCallback = $this->measurementsCallback;

            $measurements = array_map(function ($measurer) {
                return match(get_class($measurer)) {
                    ActionMeasurer::class => $measurer->result(),
                    Measurement::class => $measurer,
                    default => throw new \InvalidArgumentException("Invalid measurer class: " . get_class($measurer))
                };
            }, $this->measuredActions);
            
            $measurementsCallback($measurements);
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
                 new ($this->createProxyClass($actionToBeMeasured))($this, $originalAction)
            );
        }
    }

    private function createProxyClass(string $measure): string
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
    private \$measurer;
    private \$action;

    public function __construct(\$testable, \$action) {
    // Don't call parent constructor - we're using the wrapped action
    \$this->measurer = new \\Iak\\Action\\ActionMeasurer(\$action);
    \$this->action = \$action;
    \$testable->measuredActions[] = \$this->measurer;
    }

    public function handle(...\$args) {
    return \$this->measurer->handle(...\$args);
    }
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
}
