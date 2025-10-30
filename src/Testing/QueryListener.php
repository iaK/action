<?php

namespace Iak\Action\Testing;

use Illuminate\Support\Facades\DB;
use Iak\Action\Testing\Results\Query;
use Illuminate\Database\Events\QueryExecuted;

class QueryListener
{
    protected bool $enabled = false;
    protected array $queries = [];
    protected $listener;

    public function __construct()
    {
        // Register the listener once for this instance
        $this->listener = function (QueryExecuted $query) {
            if (! $this->enabled) {
                return;
            }

            $this->queries[] = new Query(
                $query->sql,
                $query->bindings,
                $query->time / 1000, // Convert milliseconds to seconds
                $query->connectionName ?? 'default'
            );
        };

        DB::listen($this->listener);
    }


    public function listen(callable $callback): mixed
    {
        $this->enabled = true;

        try {
            return $callback();
        } finally {
            $this->enabled = false;
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
