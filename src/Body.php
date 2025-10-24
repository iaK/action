<?php

namespace Iak\Action;

use Mockery\MockInterface;
use Mockery\LegacyMockInterface;

class Body
{
    public array $only = [];
    public array $without = [];
    
    public function without(string|object|array $classes): static
    {
        $classes = is_array($classes) ? $classes : [$classes];

        collect($classes)->map(function ($class) {
            if (is_string($class)) {
                return $class::fake();
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

    
}
