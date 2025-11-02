<?php

use Iak\Action\Testing\Testable;
use Illuminate\Support\Facades\DB;
use Iak\Action\Testing\QueryListener;
use Iak\Action\Testing\ProxyConfiguration;
use Iak\Action\Tests\TestClasses\ClosureAction;

describe('DatabaseCallProxyTrait', function () {
    it('can be used in a proxy class', function () {
        // Create a test proxy class that uses the trait with database configuration
        $proxyClass = 'TestDbCallProxy_' . uniqid();
        $code = <<<PHP
        final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
        {
        use \\Iak\\Action\\Testing\\Traits\\ProxyTrait;
        }
        PHP;
        eval($code);

        $originalAction = new ClosureAction();
        $testable = new \stdClass();
        $testable->queryListener = null;

        $config = new ProxyConfiguration(
            fn($action, $eventSource) => new QueryListener(get_class($action)),
            fn($testable, $resultData) => $testable->addQueries($resultData),
            fn($listener) => $listener->getQueries()
        );
        $proxy = new $proxyClass($testable, $originalAction, $config);

        expect($proxy)->toBeInstanceOf($proxyClass);
        expect($proxy)->toBeInstanceOf(ClosureAction::class);
        });

    it('can handle action execution through the proxy', function () {
        $proxyClass = 'TestDbCallProxy_' . uniqid();
        $code = <<<PHP
        final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
        {
        use \\Iak\\Action\\Testing\\Traits\\ProxyTrait;
        }
        PHP;
        eval($code);

        $originalAction = new ClosureAction();
        $testable = new Testable($originalAction);
        $testable->queries(function ($queries) {
            expect($queries)->toHaveCount(1);
            expect($queries[0]->query)->toBe('SELECT 1');
        });

        $config = new ProxyConfiguration(
            fn($action, $eventSource) => new QueryListener(get_class($action)),
            fn($testable, $resultData) => $testable->addQueries($resultData),
            fn($listener) => $listener->getQueries()
        );
        $proxy = new $proxyClass($testable, $originalAction, $config);
        $result = $proxy->handle(function () {
            DB::statement('SELECT 1');
            return 'Database queries executed';
        });

        expect($result)->toBe('Database queries executed');
        });
});
