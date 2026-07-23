<?php

namespace Iak\Action\PHPStan;

use Iak\Action\InlineAction;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * A fallback() value becomes the chain's result, but its closure is typed
 * `Closure(Throwable): mixed` — PHPStan cannot see a mismatch against the
 * action's handle() return type on its own. This rule closes that gap: the
 * closure's return type must be a strict subtype of handle()'s (rethrow-only
 * `never` closures pass; an undeclared handle() return means nothing to
 * check). Inline actions resolve their handle type only at the terminal
 * handle() call, so they are checked there instead.
 *
 * @implements Rule<MethodCall>
 */
final class FallbackReturnTypeRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        return match (strtolower($node->name->toString())) {
            'fallback' => $this->checkFallbackSite($node, $scope),
            'handle' => $this->checkInlineTerminal($node, $scope),
            default => [],
        };
    }

    /**
     * The class-action path: compare the fallback closure against the
     * wrapped action's declared handle() return type, right where the
     * fallback is configured.
     *
     * @return list<IdentifierRuleError>
     */
    protected function checkFallbackSite(MethodCall $node, Scope $scope): array
    {
        $actionClass = FluentChain::wrappedActionClass($node->var, $scope);

        if ($actionClass === null || $actionClass === InlineAction::class) {
            return [];
        }

        $actionType = new ObjectType($actionClass);

        if (! $actionType->hasMethod('handle')->yes()) {
            return [];
        }

        $handleReturn = $actionType->getMethod('handle', $scope)->getVariants()[0]->getReturnType();

        if ($handleReturn instanceof MixedType || $handleReturn instanceof TemplateType) {
            return [];
        }

        $closureReturn = $this->closureReturnType($node, $scope);

        if ($closureReturn === null || $handleReturn->accepts($closureReturn, true)->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "Fallback for %s returns %s, but handle() returns %s. The fallback value becomes the action's result, so it must match handle()'s return type (or rethrow to decline).",
                $actionClass,
                $closureReturn->describe(VerbosityLevel::typeOnly()),
                $handleReturn->describe(VerbosityLevel::typeOnly()),
            ))->identifier('action.fallbackReturnType')->build(),
        ];
    }

    /**
     * The inline path: at a terminal handle() on PendingAction<InlineAction>
     * the handle closure's return type is finally resolved — compare a
     * fallback() found in the same visible chain against it.
     *
     * @return list<IdentifierRuleError>
     */
    protected function checkInlineTerminal(MethodCall $node, Scope $scope): array
    {
        if (FluentChain::wrappedActionClass($node->var, $scope) !== InlineAction::class) {
            return [];
        }

        $chain = FluentChain::collect($node, $scope);

        if ($chain === null || ! isset($chain['fallback'])) {
            return [];
        }

        $fallbackCall = $chain['fallback'];

        $closureReturn = $this->closureReturnType($fallbackCall, $scope);

        if ($closureReturn === null) {
            return [];
        }

        $target = $scope->getType($node)->generalize(GeneralizePrecision::lessSpecific());

        if ($target instanceof MixedType || $target->isVoid()->yes()) {
            return [];
        }

        if ($target->accepts($closureReturn, true)->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "Fallback for the inline action returns %s, but its handle() closure returns %s. The fallback value becomes the action's result, so it must match the handle() closure's return type (or rethrow to decline).",
                $closureReturn->describe(VerbosityLevel::typeOnly()),
                $target->describe(VerbosityLevel::typeOnly()),
            ))->identifier('action.fallbackReturnType')->build(),
        ];
    }

    /**
     * The return type of the closure handed to a fallback()/handle() call —
     * via the scope's inferred type, so closure literals, first-class
     * callables and closure variables all work. Null when there is no
     * argument or it is not callable.
     */
    protected function closureReturnType(MethodCall|Node\Expr\StaticCall $call, Scope $scope): ?Type
    {
        $args = $call->getArgs();

        if ($args === []) {
            return null;
        }

        $argType = $scope->getType($args[0]->value);

        if (! $argType->isCallable()->yes()) {
            return null;
        }

        return $argType->getCallableParametersAcceptors($scope)[0]->getReturnType();
    }
}
