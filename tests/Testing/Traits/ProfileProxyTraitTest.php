<?php

use Iak\Action\Testing\Traits\ProfileProxyTrait;
use Iak\Action\Testing\Results\Profile;
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

        // Create a simple testable class for testing
        $testable = new class {
            public array $profiledActions = [];
            
            public function addProfile(Profile $profile): void
            {
                $this->profiledActions[] = $profile;
            }
        };

        $originalAction = new ClosureAction();
        $proxy = new $proxyClass($testable, $originalAction);

        expect($proxy)->toBeInstanceOf($proxyClass);
        expect($proxy)->toBeInstanceOf(ClosureAction::class);
        // Profile is only added after handle() is called
        expect($testable->profiledActions)->toHaveCount(0);
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

        // Create a simple testable class for testing
        $testable = new class {
            public array $profiledActions = [];
            
            public function addProfile(Profile $profile): void
            {
                $this->profiledActions[] = $profile;
            }
        };

        $originalAction = new ClosureAction();
        $proxy = new $proxyClass($testable, $originalAction);
        $result = $proxy->handle(function () {
            return 'Hello, World!';
        });

        expect($result)->toBe('Hello, World!');
        expect($testable->profiledActions)->toHaveCount(1);
        expect($testable->profiledActions[0])->toBeInstanceOf(Profile::class);
        });
});

