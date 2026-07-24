<?php

namespace Iak\Action\Tests\PHPStan;

use Iak\Action\PHPStan\OnceRequiresFallbackRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<OnceRequiresFallbackRule>
 */
class OnceRequiresFallbackRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new OnceRequiresFallbackRule;
    }

    public function test_once_requires_fallback(): void
    {
        $expectedMessage = static fn (string $key, string $type): string => sprintf(
            'once() answers null when the key [%s] is consumed and no fallback() is chained, but this result is typed %s — the type would lie on the skip path. Chain a fallback() (its value is checked by action.fallbackReturnType), widen the return type to include null, or use idempotent() to replay the real result.',
            $key,
            $type,
        );

        $this->analyse([__DIR__.'/Data/once-requires-fallback.php'], [
            [$expectedMessage('key', 'int'), 53],
            [$expectedMessage('key', 'int'), 78],
            [$expectedMessage('key', 'int'), 88],
        ]);
    }
}
