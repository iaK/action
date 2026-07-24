<?php

namespace Iak\Action\Tests\PHPStan;

use Iak\Action\PHPStan\FallbackReturnTypeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<FallbackReturnTypeRule>
 */
class FallbackReturnTypeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new FallbackReturnTypeRule;
    }

    public function test_fallback_return_types(): void
    {
        $this->analyse([__DIR__.'/Data/fallback-return-type.php'], [
            [
                "Fallback for Iak\Action\Tests\PHPStan\Data\IntAction returns string, but handle() returns int. The fallback value becomes the action's result, so it must match handle()'s return type (or rethrow to decline).",
                36,
            ],
            [
                "Fallback for Iak\Action\Tests\PHPStan\Data\IntAction returns string, but handle() returns int. The fallback value becomes the action's result, so it must match handle()'s return type (or rethrow to decline).",
                42,
            ],
            [
                "Fallback for Iak\Action\Tests\PHPStan\Data\IntAction returns int|string, but handle() returns int. The fallback value becomes the action's result, so it must match handle()'s return type (or rethrow to decline).",
                48,
            ],
            [
                "Fallback for Iak\Action\Tests\PHPStan\Data\IntAction returns void, but handle() returns int. The fallback value becomes the action's result, so it must match handle()'s return type (or rethrow to decline).",
                64,
            ],
            [
                "Fallback for the inline action returns string, but its handle() closure returns int. The fallback value becomes the action's result, so it must match the handle() closure's return type (or rethrow to decline).",
                87,
            ],
        ]);
    }
}
