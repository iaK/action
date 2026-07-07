<?php

namespace Iak\Action\Tests\TestClasses;

use Illuminate\Contracts\Cache\Store;

/**
 * An in-memory cache store that deliberately does NOT implement
 * Illuminate\Contracts\Cache\LockProvider, so it exercises IdempotentAction's
 * lock-less fallback path while still persisting values.
 */
class ArrayNoLockStore implements Store
{
    /** @var array<string, mixed> */
    protected array $storage = [];

    public function get($key)
    {
        return $this->storage[$key] ?? null;
    }

    public function many(array $keys)
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function put($key, $value, $seconds)
    {
        $this->storage[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->storage[$key] = (int) ($this->storage[$key] ?? 0) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, $value * -1);
    }

    public function forever($key, $value)
    {
        $this->storage[$key] = $value;

        return true;
    }

    public function forget($key)
    {
        unset($this->storage[$key]);

        return true;
    }

    public function flush()
    {
        $this->storage = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}
