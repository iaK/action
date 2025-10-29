<?php

namespace Iak\Action;

use Illuminate\Support\Facades\DB;
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

            $this->queries[] = new DatabaseCall(
                $query->sql,
                $query->bindings,
                $query->time / 1000, // Convert milliseconds to seconds
                $query->connectionName ?? 'default'
            );
        };

        DB::listen($this->listener);
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function whileEnabled(callable $callback): mixed
    {
        $this->enable();

        try {
            return $callback();
        } finally {
            $this->disable();
        }
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function clear(): void
    {
        $this->queries = [];
    }
}
