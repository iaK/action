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

        // Record through a weak reference so the connection's dispatcher does
        // not keep this listener alive: DB::listen() offers no way to remove
        // a registered listener, so a dropped QueryListener would otherwise
        // be pinned (with its recorded queries) for the container's lifetime
        // — per instrumented run under Octane. A dead reference leaves a
        // no-op closure behind instead.
        // The closure must be static - non-static closures bind $this even
        // when they do not use it, which would defeat the weak reference.
        $reference = \WeakReference::create($this);

        $this->listener = static function (QueryExecuted $query) use ($reference): void {
            $reference->get()?->record($query);
        };
    }

    /**
     * Append a record for an executed query. Only records while listen() is
     * running, so queries outside the instrumented run are ignored.
     *
     * @internal Invoked by the closure registered with DB::listen().
     */
    public function record(QueryExecuted $query): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->queries[] = new Query(
            $query->sql,
            $query->bindings,
            $query->time / 1000, // QueryExecuted::$time is milliseconds; Query::$time is seconds
            $query->connectionName,
            $this->action
        );
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

    /**
     * Total time spent in recorded queries, in seconds (like Query::$time).
     */
    public function getTotalTime(): float
    {
        return array_sum(array_map(fn ($call) => $call->time, $this->queries));
    }

    public function clear(): void
    {
        $this->queries = [];
    }
}
