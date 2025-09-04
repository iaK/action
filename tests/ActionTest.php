<?php

use Iak\Action\Tests\TestAction;
use Mockery\MockInterface;

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
