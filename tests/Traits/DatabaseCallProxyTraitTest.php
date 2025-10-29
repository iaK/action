<?php

use Iak\Action\Traits\DatabaseCallProxyTrait;
use Iak\Action\Tests\TestClasses\DatabaseAction;
use Iak\Action\QueryListener;

it('can be used in a proxy class', function () {
    // Create a test proxy class that uses the trait
    $proxyClass = 'TestDbCallProxy_' . uniqid();
    $code = <<<PHP
    final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\DatabaseAction 
    {
    use \\Iak\\Action\\Traits\\DatabaseCallProxyTrait;
    }
    PHP;
    eval($code);

    $originalAction = new DatabaseAction();
    $testable = new \stdClass();
    $testable->queryListener = null;

    $proxy = new $proxyClass($testable, $originalAction);

    expect($proxy)->toBeInstanceOf($proxyClass);
    expect($proxy)->toBeInstanceOf(DatabaseAction::class);
});

it('can handle action execution through the proxy', function () {
    $proxyClass = 'TestDbCallProxy_' . uniqid();
    $code = <<<PHP
    final class $proxyClass extends \\Iak\\Action\\Tests\\TestClasses\\DatabaseAction 
    {
    use \\Iak\\Action\\Traits\\DatabaseCallProxyTrait;
    }
    PHP;
    eval($code);

    $originalAction = new DatabaseAction();
    $testable = new \stdClass();
    $testable->queryListener = new QueryListener();

    $proxy = new $proxyClass($testable, $originalAction);
    $result = $proxy->handle();

    expect($result)->toBe('Database queries executed');
    expect($testable->queryListener->getQueries())->toHaveCount(5); // 3 CREATE/INSERT + 2 SELECT queries
});
