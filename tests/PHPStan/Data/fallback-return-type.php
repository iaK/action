<?php

namespace Iak\Action\Tests\PHPStan\Data;

use Iak\Action\Action;
use Iak\Action\Inline;
use Throwable;

class IntAction extends Action
{
    public function handle(int $x): int
    {
        return $x;
    }
}

class UntypedAction extends Action
{
    public function handle()
    {
        return 'anything';
    }
}

class NotAnAction
{
    public function fallback(\Closure $fallback): self
    {
        return $this;
    }
}

function mismatchDirect(IntAction $action): void
{
    // ERROR: string fallback on int handle()
    $action->fallback(fn (Throwable $e): string => 'nope');
}

function mismatchChained(IntAction $action): void
{
    // ERROR: same mismatch behind a retry() (callee is PendingAction<IntAction>)
    $action->retry(2)->fallback(fn (Throwable $e): string => 'nope');
}

function partialOverlap(IntAction $action): void
{
    // ERROR: int|string is not a strict subtype of int
    $action->fallback(fn (Throwable $e): int|string => $e->getCode() === 0 ? 1 : 'x');
}

function exactMatch(IntAction $action): void
{
    $action->fallback(fn (Throwable $e): int => -1);
}

function rethrowOnly(IntAction $action): void
{
    $action->fallback(fn (Throwable $e): never => throw $e);
}

function noReturnStatement(IntAction $action): void
{
    // ERROR: a bodyless closure infers void — the implicit null result would lie
    $action->fallback(function (Throwable $e): void {
        error_log($e->getMessage());
    });
}

function undeclaredHandleReturn(UntypedAction $action): void
{
    $action->fallback(fn (Throwable $e): string => 'fine — nothing declared to violate');
}

function foreignFallback(NotAnAction $notOurs): void
{
    $notOurs->fallback(fn (Throwable $e): string => 'not our method');
}

function inlineMatch(): void
{
    Inline::fallback(fn (Throwable $e): int => -1)->handle(fn (): int => 42);
}

function inlineMismatch(): void
{
    // ERROR (reported on this terminal handle() line): string fallback on int closure
    Inline::fallback(fn (Throwable $e): string => 'nope')->handle(fn (): int => 42);
}

function inlineAcrossVariables(): void
{
    $pending = Inline::fallback(fn (Throwable $e): string => 'invisible');
    $pending->handle(fn (): int => 42);
}

class ForeignHelper
{
    public function fallback(\Closure $fallback): IntAction
    {
        return new IntAction;
    }
}

class ActionWithHelper extends Action
{
    public function handle(int $x): int
    {
        return $x;
    }

    public function helper(): ForeignHelper
    {
        return new ForeignHelper;
    }
}

function foreignFallbackInMiddleOfChain(ActionWithHelper $action): void
{
    // silent: foreign fallback() in the middle of the chain
    $action->helper()->fallback(fn (Throwable $e): string => 'x')->handle(21);
}

class IntOrStringAction extends Action
{
    public function handle(): int|string
    {
        return 1;
    }
}

function narrowerSubtypeIsSilent(IntOrStringAction $action): void
{
    // silent: int is a strict subtype of int|string
    $action->fallback(fn (Throwable $e): int => -1);
}
