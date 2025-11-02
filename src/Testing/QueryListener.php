<?php

namespace Iak\Action\Testing;

use Illuminate\Support\Facades\DB;
use Iak\Action\Testing\Results\Query;
use Illuminate\Database\Events\QueryExecuted;

class QueryListener implements Listener
{
    protected bool $enabled = false;
    protected array $queries = [];
    protected $listener;
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
                $query->connectionName ?? 'default',
                $this->action
            );
        };
    }

    public function __destruct()
    {
        $this->unregisterListener();
    }

    public function listen(callable $callback): mixed
    {
        $this->enabled = true;
        $this->registerListener();

        try {
            return $callback();
        } finally {
            $this->enabled = false;
            $this->unregisterListener();
        }
    }

    protected function registerListener(): void
    {
        if (! $this->isRegistered) {
            DB::listen($this->listener);
            $this->isRegistered = true;
        }
    }

    protected function unregisterListener(): void
    {
        if ($this->isRegistered) {
            $dispatcher = DB::getEventDispatcher();
            if ($dispatcher) {
                // Note: forget() removes all listeners for this event.
                // This is acceptable in a testing context as tests should
                // set up their own listeners as needed.
                $dispatcher->forget(QueryExecuted::class);
            }
            $this->isRegistered = false;
        }
    }

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
        return array_sum(array_map(fn($call) => $call->time, $this->queries));
    }

    public function clear(): void
    {
        $this->queries = [];
    }
}
