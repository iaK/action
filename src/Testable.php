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

    public function measure(callable $callback, ?array $actions = null) 
    {
        $this->measures = $actions ?? [$this->action::class];

        $this->measurementsCallback = $callback;
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
            
            $measurementsCallback($this->measurements);
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
            
            // Create a dynamic proxy class that wraps the action
            $proxyClass = 'MeasurementProxy_' . md5($measure . spl_object_id($this));
            
            if (!class_exists($proxyClass, false)) {
                $code = <<<PHP
class $proxyClass extends \\$measure {
    public \$_testable;
    public \$_measure;
    
    public function handle(...\$args) {
        \$start = microtime(true);
        \$result = parent::handle(...\$args);
        \$end = microtime(true);
        \$this->_testable->measurements[] = new \\Iak\\Action\\Measurement(\$this->_measure, \$start, \$end);
        return \$result;
    }
}
PHP;
                eval($code);
            }
            
            app()->bind($measure, function ($app) use ($proxyClass, $measure) {
                $proxy = new $proxyClass();
                $proxy->_testable = $this;
                $proxy->_measure = $measure;
                return $proxy;
            });
        }
    }
}
