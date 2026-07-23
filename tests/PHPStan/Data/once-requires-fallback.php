<?php

namespace Iak\Action\Tests\PHPStan\Data;

use Iak\Action\Action;
use Iak\Action\Inline;
use Throwable;

class OnceIntAction extends Action
{
    public function handle(int $x): int
    {
        return $x;
    }
}

class OnceNullableAction extends Action
{
    public function handle(): ?string
    {
        return null;
    }
}

class OnceVoidAction extends Action
{
    public function handle(): void {}
}

class OnceUntypedAction extends Action
{
    public function handle()
    {
        return 'anything';
    }
}

class NotOurOnce
{
    public function once(string $key): self
    {
        return $this;
    }

    public function handle(): int
    {
        return 1;
    }
}

function onceWithoutFallback(OnceIntAction $action): void
{
    $action->once('key')->handle(21); // ERROR: non-nullable int result
}

function onceWithFallback(OnceIntAction $action): void
{
    $action->once('key')->fallback(fn (Throwable $e): int => 0)->handle(21);
}

function nullableIsHonest(OnceNullableAction $action): void
{
    $action->once('key')->handle();
}

function voidHasNothingToLieAbout(OnceVoidAction $action): void
{
    $action->once('key')->handle();
}

function undeclaredReturnIsUnchecked(OnceUntypedAction $action): void
{
    $action->once('key')->handle();
}

function thenWithoutFallback(OnceIntAction $action): void
{
    $action->once('key')->then(fn (OnceIntAction $a): int => $a->handle(21)); // ERROR
}

function thenWithNullableClosure(OnceIntAction $action): void
{
    $action->once('key')->then(fn (OnceIntAction $a): ?int => $a->handle(21));
}

function inlineOnceWithoutFallback(): void
{
    Inline::once('key')->handle(fn (): int => 42); // ERROR
}

function inlineOnceWithFallback(): void
{
    Inline::once('key')->fallback(fn (Throwable $e): int => 0)->handle(fn (): int => 42);
}

function chainAcrossVariables(OnceIntAction $action): void
{
    $pending = $action->fallback(fn (Throwable $e): int => 0);
    $pending->once('key')->handle(21); // silent: the chain below $pending is not visible
}

function deferIsNotChecked(OnceIntAction $action): void
{
    $action->once('key')->defer(fn (OnceIntAction $a): int => $a->handle(21));
}

function foreignOnce(NotOurOnce $notOurs): void
{
    $notOurs->once('key')->handle();
}

class ForeignScheduler
{
    public function once(string $frequency): OnceIntAction
    {
        return new OnceIntAction;
    }
}

class SchedulableAction extends Action
{
    public function handle(int $x): int
    {
        return $x;
    }

    public function scheduler(): ForeignScheduler
    {
        return new ForeignScheduler;
    }
}

function foreignOnceInMiddleOfChain(SchedulableAction $action): void
{
    $action->scheduler()->once('daily')->handle(21); // silent: foreign once() in the middle of the chain
}
