<?php

use Iak\Action\Testing\Traits\MeasurementProxyTrait;
use Iak\Action\Tests\TestClasses\ClosureAction;

describe('MeasurementProxyTrait', function () {
    it('can be used in a proxy class', function () {
        // Create a test proxy class that uses the trait
        $proxyClass = 'TestMeasurementProxy_' . uniqid();
        $code = <<<PHP
        final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
        {
        use \\Iak\\Action\\Testing\\Traits\\MeasurementProxyTrait;
        }
        PHP;
        eval($code);

        $originalAction = new ClosureAction();
        $testable = new \stdClass();
        $testable->measuredActions = [];

        $proxy = new $proxyClass($testable, $originalAction);

        expect($proxy)->toBeInstanceOf($proxyClass);
        expect($proxy)->toBeInstanceOf(ClosureAction::class);
        expect($testable->measuredActions)->toHaveCount(1);
        expect($testable->measuredActions[0])->toBeInstanceOf(\Iak\Action\Testing\RuntimeMeasurer::class);
        });

    it('can handle action execution through the proxy', function () {
        $proxyClass = 'TestMeasurementProxy_' . uniqid();
        $code = <<<PHP
        final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
        {
        use \\Iak\\Action\\Testing\\Traits\\MeasurementProxyTrait;
        }
        PHP;
        eval($code);

        $originalAction = new ClosureAction();
        $testable = new \stdClass();
        $testable->measuredActions = [];

        $proxy = new $proxyClass($testable, $originalAction);
        $result = $proxy->handle(function () {
            return 'Hello, World!';
        });

        expect($result)->toBe('Hello, World!');
        expect($testable->measuredActions)->toHaveCount(1);
        });
});
