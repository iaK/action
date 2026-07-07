<?php

namespace Iak\Action\Execution;

/**
 * The per-process store behind memoize(). Registered as a scoped container
 * singleton — not a static — so Octane workers and the test runner get a
 * fresh one whenever the container is rebuilt, with no state bleeding
 * between requests or tests.
 *
 * Results live in an envelope so a memoized null/false/'' still counts as a
 * hit (mirroring the idempotency cache).
 *
 * @internal Used by the Memoize middleware and Action::flushMemoized().
 */
class MemoizedResults
{
    /**
     * @var array<string, array{result: mixed}>
     */
    protected array $results = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->results);
    }

    public function get(string $key): mixed
    {
        return array_key_exists($key, $this->results) ? $this->results[$key]['result'] : null;
    }

    public function put(string $key, mixed $result): void
    {
        $this->results[$key] = ['result' => $result];
    }

    public function flush(): void
    {
        $this->results = [];
    }
}
