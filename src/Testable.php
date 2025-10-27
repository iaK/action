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
    public array $measures = [];

    public array $measurements = [];

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
            $this->measures = [$this->action::class];
            return $this;
        }

        if (is_null($callback)) {
            throw new \InvalidArgumentException('A callback is required');
        }

        $this->measures = is_array($actions) ? $actions : [$actions];

        foreach($this->measures as $measure) {
            if (!class_exists($measure) && !app()->bound($measure)) {
                throw new \InvalidArgumentException("The class or alias {$measure} is not bound to the container");
            }
        }
        
        $this->measurementsCallback = $callback;

        return $this;
    }

    public function handle(...$args)
    {
        $this->interceptCalls();

        $action = $this->action;

        if (in_array($this->action::class, $this->measures)) {
            $start = microtime(true);
            $result = $action->handle(...$args);
            $end = microtime(true);
            $this->measurements[] = new Measurement($this->action::class, $start, $end);
        } else {
            $result = $action->handle(...$args);
        }

        if (isset($this->measurementsCallback)) {
            $measurementsCallback = $this->measurementsCallback;
            
            // Convert ActionMeasurer instances to Measurement instances
            $measurements = array_map(function ($measurer) {
                return $measurer instanceof ActionMeasurer ? $measurer->result() : $measurer;
            }, $this->measurements);
            
            $measurementsCallback($measurements);
        }

        return $result;
    }

    private function interceptCalls(): void
    {
        if (!empty($this->only)) {
            app()->beforeResolving(function ($object, $app) {
                if (!class_exists($object)) {
                    return;
                }

                $reflection = new \ReflectionClass($object);

                if (!$reflection->isSubclassOf(Action::class)) {
                    return;
                }

                // Mock all actions that are NOT in the only array
                if (!in_array($object, $this->only)) {
                    $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

                    array_shift($trace);

                    foreach ($trace as $frame) {
                        if (!isset($frame['object']) || $frame['object'] === $this) {
                            continue;
                        }
                
                        $ancestor = $frame['object'];
                        
                        if ($ancestor instanceof ($this->action::class)) {
                            $object::fake()->shouldReceive('handle');
                            break;
                        }
                    }
                }
            });
        }

        // Intercept nested action resolution for measurement
        foreach ($this->measures as $measure) {
            // Skip the main action - it's measured differently
            if ($measure === $this->action::class) {
                continue;
            }

            if (!class_exists($measure)) {
                throw new \InvalidArgumentException("Invalid measure class: $measure");
            }
            
            // Create a dynamic proxy class that extends the action and uses ActionMeasurer
            $proxyClass = 'MeasurementProxy_' . md5($measure . spl_object_id($this));
            $fqcn = '\\' . ltrim($measure, '\\');

            $code = <<<PHP
final class $proxyClass extends $fqcn 
{
    private \$measurer;
    private \$action;

    public function __construct(\$testable, \$action) {
        // Don't call parent constructor - we're using the wrapped action
        \$this->measurer = new \\Iak\\Action\\ActionMeasurer(\$action);
        \$this->action = \$action;
        \$testable->measurements[] = \$this->measurer;
    }

    public function handle(...\$args) {
        return \$this->measurer->handle(...\$args);
    }
}
PHP;
            eval($code);
            
            // Resolve the original action BEFORE binding to avoid infinite loop
            $originalAction = $measure::make();
            
            app()->bind($measure, function ($app) use ($proxyClass, $originalAction) {
                return new $proxyClass($this, $originalAction);
            });
        }
    }
}
