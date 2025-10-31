<?php

use Mockery\MockInterface;
use Iak\Action\Tests\TestClasses\ClosureAction;

it('can be instantiated', function () {
    $action = ClosureAction::make();

    expect($action)->toBeInstanceOf(ClosureAction::class);
});

it('can be faked', function () {
    $action = ClosureAction::fake();

    expect($action)->toBeInstanceOf(MockInterface::class);
});

it('can create fake action with custom alias', function () {
    $fake = ClosureAction::fake('custom.test.action');
    
    expect($fake)->toBeInstanceOf(MockInterface::class);
    expect(app('custom.test.action'))->toBe($fake);
});
