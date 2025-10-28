<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\FireEventAction;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\MiddleManAction;
use Iak\Action\Tests\TestClasses\DeeplyNestedAction;

it('can emit events', function () {
    $eventsCalled = [];
    FireEventAction::make()
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
    $action = new FireEventAction(true);

    expect(fn () => $action->handle())
        ->toThrow(InvalidArgumentException::class, "Cannot emit event 'test.event.c'. Did you mean: 'test.event.a'?");
});

it('throws an exception if listening to an event that is not allowed', function () {
    $action = FireEventAction::make();

    expect(fn () => $action->on('test.event.c', function () {}))
        ->toThrow(InvalidArgumentException::class, "Cannot listen for event 'test.event.c'. Did you mean: 'test.event.a'?");
});

it('isolates events for each action instance', function () {
    $actionA = FireEventAction::make();
    $actionB = FireEventAction::make();

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

it('can listen to nested events if forwarded', function () {
    $eventsCalled = [];
    ClosureAction::make()
        ->on('test.event.a', function ($data) use (&$eventsCalled) {
            $eventsCalled[] = 'test.event.a';
            expect($data)->toBe('Hello, World!');
        })->handle(function () {
            (new class {
                public function handle()
                {
                    FireEventAction::make()
                        ->forwardEvents(['test.event.a', 'test.event.b'])
                        ->handle();
                }
            })->handle();
        });

    expect($eventsCalled)->toBe(['test.event.a']);
});

it('forwards all events if no events are specified', function () {
    $eventsCalled = [];
    ClosureAction::make()
        ->on('test.event.a', function ($data) use (&$eventsCalled) {
            $eventsCalled[] = 'test.event.a';
            expect($data)->toBe('Hello, World!');
        })->handle(function () {
            (new class {
                public function handle()
                {
                    FireEventAction::make()
                        ->forwardEvents()
                        ->handle();
                }
            })->handle();
        });

    expect($eventsCalled)->toBe(['test.event.a']);
});

it('does not forward events if forwarding is disabled', function () {
    $eventsCalled = [];
    ClosureAction::make()
        ->on('test.event.a', function () use (&$eventsCalled) {
            $eventsCalled[] = 'test.event.a';
        })->handle(function () {
            (new class {
                public function handle()
                {
                    FireEventAction::make()
                        ->handle();
                }
            })->handle();
        });

    expect($eventsCalled)->toBe([]);
});

it('cleans up event listeners on destruction', function () {
    $action = FireEventAction::make();
    $action->on('test.event.a', function () {});
    
    // Verify event listener is registered by checking if it exists
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('generateEventName');
    $method->setAccessible(true);
    $eventName = $method->invoke($action, 'test.event.a');
    
    expect(\Illuminate\Support\Facades\Event::hasListeners($eventName))->toBeTrue();
    
    unset($action);
    
    // Event listener should be cleaned up
    expect(\Illuminate\Support\Facades\Event::hasListeners($eventName))->toBeFalse();
});

it('handles edge case with circular event propagation', function () {
    $eventsReceived = [];
    
    $action1 = FireEventAction::make();
    $action2 = FireEventAction::make();
    
    $action1->on('test.event.a', function ($data) use (&$eventsReceived, $action2) {
        $eventsReceived[] = $data;
        // This could cause circular propagation, but should be handled
        $action2->event('test.event.a', $data);
    });
    
    $action1->handle();
    
    // Should not cause infinite loop - the propagation should be limited
    expect($eventsReceived)->toHaveCount(1);
});

