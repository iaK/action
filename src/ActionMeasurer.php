<?php

namespace Iak\Action;

class ActionMeasurer
{
    public string $start;
    public string $end;

    public function __construct(private Action $action)
    {
        
    }

    public function handle(...$arguments)
    {
        $this->start = microtime(true);
        $result = $this->action->handle(...$arguments);
        $this->end = microtime(true);

        return $result;
    }

    public function result(): Measurement
    {
        return new Measurement($this->action::class, $this->start, $this->end);
    }

    public function __call($name, $arguments)
    {
        return $this->action->$name(...$arguments);
    }
}
