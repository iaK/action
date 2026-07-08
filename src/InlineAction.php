<?php

namespace Iak\Action;

use Closure;
use UnitEnum;

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
     * The events this instance may emit. Instance-level on purpose: an
     * inline action has no class of its own to carry #[EmitsEvents].
     *
     * @var array<int, string>
     */
    protected array $allowedEvents = [];

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

    /**
     * Declare the events this inline action may emit — the fluent twin of
     * the #[EmitsEvents] attribute. Called by Inline::events(); enum cases
     * normalize like everywhere else in the event API.
     *
     * @param  array<int, string|UnitEnum>  $events
     * @return $this
     *
     * @internal Seeded by Inline::events() before the wrapper opens. Calling it mid-chain through the PendingAction mixin would return the bare action and silently drop every configured wrapper — declare events at the entry instead.
     */
    public function allowEvents(array $events): static
    {
        $this->allowedEvents = array_map(EventName::normalize(...), $events);

        return $this;
    }

    /**
     * Instance-backed override of the trait's attribute reflection: allowed
     * events live on the instance here, so the per-class static cache must
     * not apply. The class is final and never carries #[EmitsEvents], so
     * the property is the whole truth.
     *
     * @return array<int, string>
     */
    public function getAllowedEvents(): array
    {
        return $this->allowedEvents;
    }
}
