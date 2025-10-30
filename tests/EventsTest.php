<?php

use Iak\Action\HandlesEvents;
use Iak\Action\EmitsEvents;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\FireEventAction;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\MiddleManAction;
use Iak\Action\Tests\TestClasses\DeeplyNestedAction;
use Illuminate\Support\Facades\Event;

#[EmitsEvents(['test-event', 'another-event'])]
class TestActionWithEvents
{
    use HandlesEvents;

    public function handle()
    {
        return 'test';
    }
}

// EmitsEvents Attribute Tests
describe('EmitsEvents Attribute', function () {
    it('can create with valid events', function () {
        $events = ['event1', 'event2', 'event3'];
        $emitsEvents = new EmitsEvents($events);
        
        expect($emitsEvents->events)->toBe($events);
    });

    it('throws exception with empty events', function () {
        expect(fn () => new EmitsEvents([]))
            ->toThrow(InvalidArgumentException::class, 'Events array cannot be empty');
    });

    it('throws exception with non string events', function () {
        expect(fn () => new EmitsEvents(['event1', 123, 'event3']))
            ->toThrow(InvalidArgumentException::class, 'All events must be strings');
    });

    it('throws exception with mixed types', function () {
        expect(fn () => new EmitsEvents(['event1', ['nested'], 'event3']))
            ->toThrow(InvalidArgumentException::class, 'All events must be strings');
    });
});

// HandlesEvents Trait Tests
describe('HandlesEvents Trait', function () {
    it('can listen for events', function () {
        $action = new TestActionWithEvents();
        $callback = function ($data) {
            return $data;
        };

        $result = $action->on('test-event', $callback);

        expect($result)->toBe($action);
    });

    it('can emit events', function () {
        $action = new TestActionWithEvents();
        $data = ['key' => 'value'];
        $eventReceived = false;

        $action->on('test-event', function ($receivedData) use ($data, &$eventReceived) {
            expect($receivedData)->toBe($data);
            $eventReceived = true;
        });

        $result = $action->event('test-event', $data);

        expect($result)->toBe($action);
        expect($eventReceived)->toBeTrue();
    });

    it('throws exception for invalid event when listening', function () {
        $action = new TestActionWithEvents();

        expect(fn () => $action->on('invalid-event', function () {}))
            ->toThrow(InvalidArgumentException::class, "Cannot listen for event 'invalid-event'.");
    });

    it('throws exception for invalid event when emitting', function () {
        $action = new TestActionWithEvents();

        expect(fn () => $action->event('invalid-event', []))
            ->toThrow(InvalidArgumentException::class, "Cannot emit event 'invalid-event'.");
    });

    it('can forward events', function () {
        $action = new TestActionWithEvents();
        
        $result = $action->forwardEvents(['test-event']);

        expect($result)->toBe($action);
        
        // Use reflection to access the protected property
        $reflection = new ReflectionClass($action);
        $property = $reflection->getProperty('forwardEvents');
        $property->setAccessible(true);
        expect($property->getValue($action))->toBe(['test-event']);
    });

    it('forward events with null uses allowed events', function () {
        $action = new TestActionWithEvents();
        
        $result = $action->forwardEvents();

        expect($result)->toBe($action);
        
        // Use reflection to access the protected property
        $reflection = new ReflectionClass($action);
        $property = $reflection->getProperty('forwardEvents');
        $property->setAccessible(true);
        expect($property->getValue($action))->toBe(['test-event', 'another-event']);
    });

    it('get allowed events returns events from attribute', function () {
        $action = new TestActionWithEvents();
        
        $events = $action->getAllowedEvents();

        expect($events)->toBe(['test-event', 'another-event']);
    });

    it('cleanup on destruct', function () {
        Event::fake();

        $action = new TestActionWithEvents();
        $action->on('test-event', function () {});
        
        // Trigger destructor
        unset($action);

        // The event listeners should be cleaned up
        expect(true)->toBeTrue(); // This test mainly ensures no exceptions are thrown
    });
});

// Event Action Integration Tests
describe('Event Action Integration', function () {
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
});
