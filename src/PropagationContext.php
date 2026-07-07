<?php

namespace Iak\Action;

use WeakReference;

/**
 * The ordered chain of HandlesEvents ancestors present on the call stack at
 * the moment forwardEvents() was called, nearest first.
 *
 * Captured once per forwardEvents() call and consulted on every event()
 * emission, replacing a per-emission debug_backtrace walk. Ancestors are held
 * weakly, so a captured context never extends the lifetime of the objects it
 * points at.
 *
 * @internal
 */
final class PropagationContext
{
    /**
     * @param  list<WeakReference<object>>  $ancestors
     */
    private function __construct(
        private array $ancestors,
    ) {}

    /**
     * Capture the trait-using ancestors currently on the call stack.
     *
     * This is the only place the event system inspects the call stack, and it
     * runs once per forwardEvents() call rather than once per emitted event.
     *
     * @param  callable(object): bool  $usesHandlesEvents
     */
    public static function capture(object $emitter, callable $usesHandlesEvents): self
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS);

        $ancestors = [];
        $seen = [];

        foreach ($trace as $frame) {
            $object = $frame['object'] ?? null;

            if ($object === null || $object === $emitter) {
                continue;
            }

            $id = spl_object_id($object);

            if (isset($seen[$id]) || ! $usesHandlesEvents($object)) {
                continue;
            }

            $seen[$id] = true;
            $ancestors[] = WeakReference::create($object);
        }

        return new self($ancestors);
    }

    /**
     * The captured ancestors, nearest first. Entries whose object has been
     * garbage collected resolve to null and must be skipped by the caller.
     *
     * @return list<WeakReference<object>>
     */
    public function ancestors(): array
    {
        return $this->ancestors;
    }

    /**
     * WeakReference cannot be serialized, so a captured context does not
     * survive serialization: an unserialized object propagates nowhere until
     * forwardEvents() is called again.
     *
     * @return array{}
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param  array{}  $data
     */
    public function __unserialize(array $data): void
    {
        $this->ancestors = [];
    }
}
