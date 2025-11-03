<?php

namespace Iak\Action\Testing;

use Iak\Action\Testing\Results\Query;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class QueryListener implements Listener
{
    protected bool $enabled = false;

    /** @var Query[] */
    protected array $queries = [];

    protected \Closure $listener;

    protected ?string $action;

    protected bool $isRegistered = false;

    public function __construct(?string $action = null)
    {
        $this->action = $action;

        // Create the listener closure (but don't register it yet)
        $this->listener = function (QueryExecuted $query) {
            if (! $this->enabled) {
                return;
            }

            $this->queries[] = new Query(
                $query->sql,
                $query->bindings,
                $query->time / 1000, // Convert milliseconds to seconds
                $query->connectionName,
                $this->action
            );
        };
    }

    public function listen(callable $callback): mixed
    {
        $this->enabled = true;
        $this->registerListener();

        try {
            return $callback();
        } finally {
            $this->enabled = false;
        }
    }

    protected function registerListener(): void
    {
        if (! $this->isRegistered) {
            DB::listen($this->listener);
            $this->isRegistered = true;
        }
    }

    /**
     * @return Query[]
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getCallCount(): int
    {
        return count($this->queries);
    }

    public function getTotalTime(): float
    {
        return array_sum(array_map(fn ($call) => $call->time, $this->queries));
    }

    public function clear(): void
    {
        $this->queries = [];
    }
}
