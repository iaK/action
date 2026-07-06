<?php

namespace Iak\Action\Tests\TestClasses;

/**
 * A plain subclass of ClosureAction with no #[EmitsEvents] attribute of its own,
 * used to exercise getAllowedEvents()'s parent-fallback path (the same path eval'd
 * proxy classes rely on to resolve their events).
 */
class ClosureActionChild extends ClosureAction
{
    //
}
