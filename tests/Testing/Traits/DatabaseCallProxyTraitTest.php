<?php

use Illuminate\Support\Facades\DB;
use Iak\Action\Testing\QueryListener;
use Iak\Action\Tests\TestClasses\ClosureAction;

it('can be used in a proxy class', function () {
    // Create a test proxy class that uses the trait
    $proxyClass = 'TestDbCallProxy_' . uniqid();
    $code = <<<PHP
    final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
    {
    use \\Iak\\Action\\Testing\\Traits\\DatabaseCallProxyTrait;
    }
    PHP;
    eval($code);

    $originalAction = new ClosureAction();
    $testable = new \stdClass();
    $testable->queryListener = null;

    $proxy = new $proxyClass($testable, $originalAction);

    expect($proxy)->toBeInstanceOf($proxyClass);
    expect($proxy)->toBeInstanceOf(ClosureAction::class);
});

it('can handle action execution through the proxy', function () {
    $proxyClass = 'TestDbCallProxy_' . uniqid();
    $code = <<<PHP
    final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\ClosureAction 
    {
    use \\Iak\\Action\\Testing\\Traits\\DatabaseCallProxyTrait;
    }
    PHP;
    eval($code);

    $originalAction = new ClosureAction();
    $testable = new \stdClass();
    $testable->queryListener = new QueryListener();

    $proxy = new $proxyClass($testable, $originalAction);
    $result = $proxy->handle(function () {
        DB::statement('SELECT 1');
        return 'Database queries executed';
    });

    expect($result)->toBe('Database queries executed');
    expect($testable->queryListener->getQueries())->toHaveCount(1); // 3 CREATE/INSERT + 2 SELECT queries
});
