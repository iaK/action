<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\HandlesEvents;

/**
 * Composes HandlesEvents one trait removed, so tests can pin that ancestor
 * detection sees through trait-of-trait composition.
 */
trait ComposesHandlesEvents
{
    use HandlesEvents;
}
