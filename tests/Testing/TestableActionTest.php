<?php

use Mockery\MockInterface;
use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\LogAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;

it('can create testable action with callback', function () {
    $callbackExecuted = false;
    $testable = ClosureAction::test(function ($testable) use (&$callbackExecuted) {
        $callbackExecuted = true;
        expect($testable)->toBeInstanceOf(Testable::class);
    });
    
    expect($callbackExecuted)->toBeTrue();
    expect($testable)->toBeInstanceOf(Testable::class);
});

it('can mock actions inside other actions', function () {
    ClosureAction::test()
        ->only(ClosureAction::class)
        ->handle(function () {
            ClosureAction::make()->handle();
            OtherClosureAction::make()->handle();
        });

    expect(OtherClosureAction::make())
        ->tobeinstanceof(MockInterface::class);
});

it('can use without method with string class', function () {
    ClosureAction::test()
        ->without(OtherClosureAction::class)
        ->handle();
    
    expect(OtherClosureAction::make())->toBeInstanceOf(MockInterface::class);
});

it('can use without method with array of classes', function () {
    ClosureAction::test()
        ->without([OtherClosureAction::class, ClosureAction::class])
        ->handle(function () {
            OtherClosureAction::make()->handle();
            ClosureAction::make()->handle();
        });
    
    // Both classes should be mocked when resolved
    expect(OtherClosureAction::make())->toBeInstanceOf(MockInterface::class);
    expect(ClosureAction::make())->toBeInstanceOf(MockInterface::class);
});


it('can use without method and specify return value', function () {
    $result = ClosureAction::test()
        ->without([OtherClosureAction::class => 'Mocked hello, World!'])
        ->handle(function () {
            return OtherClosureAction::make()->handle();
        });
    
    expect($result)->toBe('Mocked hello, World!');
});

it('can use without method and specify return value for several actions', function () {
    $result = ClosureAction::test()
        ->without([
            // Not nested
            ClosureAction::class => 'Mocked hello, World!',
            // Nested
            [OtherClosureAction::class => 'Mocked again!'],
        ])
        ->handle(function () {
            return ClosureAction::make()->handle() . ' ' . OtherClosureAction::make()->handle();
        });
    
    expect($result)->toBe('Mocked hello, World! Mocked again!');
});

it('can use only method with array parameter', function () {
    ClosureAction::test()
        ->only([ClosureAction::class, OtherClosureAction::class])
        ->handle(function () {
            LogAction::make()->handle();
            OtherClosureAction::make()->handle();
            ClosureAction::make()->handle();
        });
    
    expect(LogAction::make())->toBeInstanceOf(MockInterface::class);
    expect(OtherClosureAction::make())->toBeInstanceOf(OtherClosureAction::class);
    expect(ClosureAction::make())->toBeInstanceOf(ClosureAction::class);
});

it('throws exception when without method receives invalid parameter', function () {
    expect(fn () => ClosureAction::test()->without(123))
        ->toThrow(Exception::class);
});



