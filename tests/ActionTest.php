<?php

use Iak\Action\Testable;
use Iak\Action\Action;
use Mockery\MockInterface;
use Iak\Action\Tests\TestClasses\TestAction;
use Iak\Action\Tests\TestClasses\MiddleManAction;
use Iak\Action\Tests\TestClasses\SecondAction;
use Iak\Action\Tests\TestClasses\DeeplyNestedAction;

it('can be instantiated', function () {
    $action = TestAction::make();

    expect($action)->toBeInstanceOf(TestAction::class);
    expect($action->handle())->toBe('Hello, World!');
});

it('can be faked', function () {
    $action = TestAction::fake();

    expect($action)->toBeInstanceOf(MockInterface::class);
});

it('can emit events', function () {
    $eventsCalled = [];
    TestAction::make()
        ->on('test.event.a', function ($data) use (&$eventsCalled) {
            $eventsCalled[] = 'test.event.a';
            expect($data)->toBe('Hello, World!');
        })
        ->on('test.event.b', function ($data) use (&$eventsCalled) {
            $eventsCalled[] = 'test.event.b';
            expect($data)->toBe(['Hello', 'World']);
        })
        ->handle();

    expect($eventsCalled)->toBe(['test.event.a', 'test.event.b']);
});

it('throws an exception if an event is not allowed', function () {
    $action = new TestAction(true);

    expect(fn () => $action->handle())
        ->toThrow(InvalidArgumentException::class, "Cannot emit event 'test.event.c'. Did you mean: 'test.event.a'?");
});

it('throws an exception if listening to an event that is not allowed', function () {
    $action = TestAction::make();

    expect(fn () => $action->on('test.event.c', function () {}))
        ->toThrow(InvalidArgumentException::class, "Cannot listen for event 'test.event.c'. Did you mean: 'test.event.a'?");
});

it('isolates events for each action instance', function () {
    $actionA = TestAction::make();
    $actionB = TestAction::make();

    $eventsCalledForA = [];
    $eventsCalledForB = [];

    $actionA->on('test.event.a', function () use (&$eventsCalledForA) {
        $eventsCalledForA[] = 'test.event.a';
    });
    $actionB->on('test.event.a', function () use (&$eventsCalledForB) {
        $eventsCalledForB[] = 'test.event.a';
    });

    $actionA->handle();
    $actionB->handle();

    expect($eventsCalledForA)->toBe(['test.event.a']);
    expect($eventsCalledForB)->toBe(['test.event.a']);
});

it('can listen thorugh a middle man', function () {
    MiddleManAction::make()
        ->on('test.event.a', function ($data) {
            expect($data)->toBe('Hello, World!');
        })->handle();
});

it('can handle deeply nested actions', function () {
    (new DeeplyNestedAction())
        ->on('test.event.a', function ($data) {
            expect($data)->toBe('Hello, World!');
        })
        ->handle();
});

it('can mock actions inside other actions', function () {
    MiddleManAction::make()
        ->within(function (Testable $testable) {
            $testable->only(TestAction::class);
        })->handle();

    expect(SecondAction::make())
        ->tobeinstanceof(MockInterface::class);
});
