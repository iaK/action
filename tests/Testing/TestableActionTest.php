<?php

use Iak\Action\Testing\Testable;
use Mockery\MockInterface;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\FireEventAction;

it('can create testable action with callback', function () {
    $callbackExecuted = false;
    $testable = FireEventAction::test(function ($testable) use (&$callbackExecuted) {
        $callbackExecuted = true;
        expect($testable)->toBeInstanceOf(Testable::class);
    });
    
    expect($callbackExecuted)->toBeTrue();
    expect($testable)->toBeInstanceOf(Testable::class);
});

it('can mock actions inside other actions', function () {
    ClosureAction::test()
        ->only(FireEventAction::class)
        ->handle(function () {
            FireEventAction::make()->handle();
            SayHelloAction::make()->handle();
        });

    expect(SayHelloAction::make())
        ->tobeinstanceof(MockInterface::class);
});

it('can use without method with string class', function () {
    FireEventAction::test()
        ->without(SayHelloAction::class)
        ->handle();
    
    expect(SayHelloAction::make())->toBeInstanceOf(MockInterface::class);
});

it('can use without method with array of classes', function () {
    ClosureAction::test()
        ->without([SayHelloAction::class, ClosureAction::class])
        ->handle(function () {
            SayHelloAction::make()->handle();
            ClosureAction::make()->handle();
        });
    
    // Both classes should be mocked when resolved
    expect(SayHelloAction::make())->toBeInstanceOf(MockInterface::class);
    expect(ClosureAction::make())->toBeInstanceOf(MockInterface::class);
});


it('can use without method and specify return value', function () {
    $result = ClosureAction::test()
        ->without([SayHelloAction::class => 'Mocked hello, World!'])
        ->handle(function () {
            return SayHelloAction::make()->handle();
        });
    
    expect($result)->toBe('Mocked hello, World!');
});

it('can use without method and specify return value for several actions', function () {
    $result = ClosureAction::test()
        ->without([
            // Not nested
            SayHelloAction::class => 'Mocked hello, World!',
            // Nested
            [FireEventAction::class => 'Mocked event!'],
        ])
        ->handle(function () {
            return SayHelloAction::make()->handle() . ' ' . FireEventAction::make()->handle();
        });
    
    expect($result)->toBe('Mocked hello, World! Mocked event!');
});

it('can use only method with array parameter', function () {
    ClosureAction::test()
        ->only([FireEventAction::class, SayHelloAction::class])
        ->handle(function () {
            SayHelloAction::make()->handle();
            FireEventAction::make()->handle();
            ClosureAction::make()->handle();
        });
    
    expect(FireEventAction::make())->toBeInstanceOf(FireEventAction::class);
    expect(SayHelloAction::make())->toBeInstanceOf(SayHelloAction::class);
    expect(ClosureAction::make())->toBeInstanceOf(MockInterface::class);
});

it('throws exception when without method receives invalid parameter', function () {
    expect(fn () => FireEventAction::test()->without(123))
        ->toThrow(Exception::class);
});



