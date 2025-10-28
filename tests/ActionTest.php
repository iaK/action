<?php

use Mockery\MockInterface;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\FireEventAction;

it('can be instantiated', function () {
    $action = SayHelloAction::make();

    expect($action)->toBeInstanceOf(SayHelloAction::class);
});

it('can be faked', function () {
    $action = SayHelloAction::fake();

    expect($action)->toBeInstanceOf(MockInterface::class);
});

it('can create fake action with custom alias', function () {
    $fake = SayHelloAction::fake('custom.test.action');
    
    expect($fake)->toBeInstanceOf(MockInterface::class);
    expect(app('custom.test.action'))->toBe($fake);
});
