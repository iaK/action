<?php

namespace Iak\Action\PHPStan;

use Iak\Action\Action;
use Iak\Action\Inline;
use Iak\Action\PendingAction;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

/**
 * Walks a fluent wrapper chain backwards from a terminal call, collecting
 * the statically visible calls by name. A chain only counts as fully
 * visible when it roots in a bare action (every wrapper the expression ever
 * saw is then part of the walk) or in a static entry point on Inline or an
 * Action subclass. Anything else — a variable holding a preconfigured
 * PendingAction, a dynamic method name, a foreign factory — returns null so
 * the rules stay silent rather than guess.
 *
 * @internal Shared by the shipped PHPStan rules.
 */
final class FluentChain
{
    /**
     * The chain's calls keyed by lowercased method name (terminal excluded;
     * the call closest to the terminal wins on duplicates), or null when the
     * chain is not fully statically visible.
     *
     * @return array<string, MethodCall|StaticCall>|null
     */
    public static function collect(MethodCall $terminal, Scope $scope): ?array
    {
        $calls = [];
        $node = $terminal->var;

        while ($node instanceof MethodCall) {
            if (! $node->name instanceof Identifier) {
                return null;
            }

            $calls[strtolower($node->name->toString())] ??= $node;
            $node = $node->var;
        }

        if ($node instanceof StaticCall) {
            if (! $node->name instanceof Identifier || ! $node->class instanceof Name) {
                return null;
            }

            $rootClass = $scope->resolveName($node->class);

            if ($rootClass !== Inline::class
                && ! (new ObjectType(Action::class))->isSuperTypeOf(new ObjectType($rootClass))->yes()) {
                return null;
            }

            $calls[strtolower($node->name->toString())] ??= $node;

            return $calls;
        }

        if ((new ObjectType(Action::class))->isSuperTypeOf($scope->getType($node))->yes()) {
            return $calls;
        }

        return null;
    }

    /**
     * The wrapped action's class name behind a callee expression: the callee
     * itself when it is an action, or the TAction template argument when it
     * is a PendingAction. Null when neither resolves to a single class.
     */
    public static function wrappedActionClass(Expr $callee, Scope $scope): ?string
    {
        $calleeType = $scope->getType($callee);

        if ((new ObjectType(Action::class))->isSuperTypeOf($calleeType)->yes()) {
            $names = $calleeType->getObjectClassNames();

            return count($names) === 1 ? $names[0] : null;
        }

        if ((new ObjectType(PendingAction::class))->isSuperTypeOf($calleeType)->yes()) {
            $action = $calleeType->getTemplateType(PendingAction::class, 'TAction');
            $names = $action->getObjectClassNames();

            return count($names) === 1 ? $names[0] : null;
        }

        return null;
    }
}
