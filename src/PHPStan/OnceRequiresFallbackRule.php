<?php

namespace Iak\Action\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * A consumed once() key answers null unless a fallback() is chained, but the
 * chain's result type stays the action's own return type — a lie for every
 * non-nullable action. This rule fires at the terminal handle()/then() call
 * when the visible chain configures once() without fallback() and the result
 * type does not already include null. Together with
 * FallbackReturnTypeRule the pair guarantees a well-typed result: a value
 * always exists, and it is always the right type.
 *
 * @implements Rule<MethodCall>
 */
final class OnceRequiresFallbackRule implements Rule
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

        if (! in_array(strtolower($node->name->toString()), ['handle', 'then'], true)) {
            return [];
        }

        // Only terminal calls on the wrapper: a bare $action->handle() has no
        // once(), and Testable::then() is not a PendingAction callee.
        if (FluentChain::wrappedActionClass($node->var, $scope) === null) {
            return [];
        }

        $chain = FluentChain::collect($node, $scope);

        if ($chain === null || ! isset($chain['once']) || isset($chain['fallback'])) {
            return [];
        }

        $resultType = $scope->getType($node);

        if ($resultType instanceof MixedType
            || $resultType instanceof NeverType
            || $resultType->isVoid()->yes()
            || TypeCombinator::containsNull($resultType)) {
            return [];
        }

        $described = $resultType
            ->generalize(GeneralizePrecision::lessSpecific())
            ->describe(VerbosityLevel::typeOnly());

        return [
            RuleErrorBuilder::message(sprintf(
                'once() answers null when the key %s is consumed and no fallback() is chained, but this result is typed %s — the type would lie on the skip path. Chain a fallback() (its value is checked by action.fallbackReturnType), widen the return type to include null, or use idempotent() to replay the real result.',
                $this->describeKey($chain['once'], $scope),
                $described,
            ))->identifier('action.onceRequiresFallback')->build(),
        ];
    }

    /**
     * The once() key rendered as [key] when it is a single known constant
     * string, or a neutral placeholder when it is dynamic.
     */
    protected function describeKey(MethodCall|Node\Expr\StaticCall $onceCall, Scope $scope): string
    {
        $args = $onceCall->getArgs();

        if ($args === []) {
            return '[unknown]';
        }

        $strings = $scope->getType($args[0]->value)->getConstantStrings();

        return count($strings) === 1 ? '['.$strings[0]->getValue().']' : '[unknown]';
    }
}
