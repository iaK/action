<?php

namespace Iak\Action;

use Closure;

/**
 * The concrete action behind Inline: it holds no state of its own and simply
 * invokes the closure handed to handle(), passing itself along so the closure
 * can reach the action API (event(), recordMemory()) through a parameter —
 * $this inside the closure keeps its natural binding to the enclosing scope.
 *
 * Every inline action in the app shares this one class, so every class-scoped
 * default (idempotency key prefixes, log context attribution, class-derived
 * wrapper keys) is shared too. The Inline entry points and the PendingAction
 * guards require explicit keys where that sharing would be dangerous.
 */
final class InlineAction extends Action
{
    /**
     * Invoke the closure as the action body.
     *
     * @template TReturn
     *
     * @param  Closure(InlineAction): TReturn  $closure
     * @return TReturn
     */
    public function handle(Closure $closure): mixed
    {
        return $closure($this);
    }
}
