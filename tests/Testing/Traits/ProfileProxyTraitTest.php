<?php

use Iak\Action\Testing\Traits\ProfileProxyTrait;
use Iak\Action\Tests\TestClasses\ClosureAction;

describe('ProfileProxyTrait', function () {
    it('can be used in a proxy class', function () {
        // Create a test proxy class that uses the trait
        $proxyClass = 'TestProfileProxy_' . uniqid();
        $code = <<<PHP
        final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
        {
        use \\Iak\\Action\\Testing\\Traits\\ProfileProxyTrait;
        }
        PHP;
        eval($code);

        $originalAction = new ClosureAction();
        $testable = new \stdClass();
        $testable->profiledActions = [];

        $proxy = new $proxyClass($testable, $originalAction);

        expect($proxy)->toBeInstanceOf($proxyClass);
        expect($proxy)->toBeInstanceOf(ClosureAction::class);
        expect($testable->profiledActions)->toHaveCount(1);
        expect($testable->profiledActions[0])->toBeInstanceOf(\Iak\Action\Testing\RuntimeProfiler::class);
        });

    it('can handle action execution through the proxy', function () {
        $proxyClass = 'TestProfileProxy_' . uniqid();
        $code = <<<PHP
        final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
        {
        use \\Iak\\Action\\Testing\\Traits\\ProfileProxyTrait;
        }
        PHP;
        eval($code);

        $originalAction = new ClosureAction();
        $testable = new \stdClass();
        $testable->profiledActions = [];

        $proxy = new $proxyClass($testable, $originalAction);
        $result = $proxy->handle(function () {
            return 'Hello, World!';
        });

        expect($result)->toBe('Hello, World!');
        expect($testable->profiledActions)->toHaveCount(1);
        });
});

