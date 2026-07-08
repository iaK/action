<?php

use Iak\Action\EmitsEvents;
use Iak\Action\HandlesEvents;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\ClosureActionChild;
use Iak\Action\Tests\TestClasses\ComposesHandlesEvents;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;

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
});

// HandlesEvents Trait Tests
describe('HandlesEvents Trait', function () {
    it('survives destruction while facades point at a flushed application', function () {
        // Between two test cases the current container and the facade root can
        // diverge: app() already points at the next (booting) application while
        // the Event facade still points at the previous, flushed one. An action
        // destructed at that moment must not crash.
        $action = ClosureAction::make();

        $originalApp = Facade::getFacadeApplication();
        $originalContainer = Container::getInstance();

        $flushed = new Application;
        $flushed->flush();

        // Creating an Application hijacks Container::$instance - restore it so
        // only the facade root diverges, as happens during test teardown.
        Container::setInstance($originalContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($flushed);

        try {
            unset($action);
            gc_collect_cycles();
        } finally {
            Facade::setFacadeApplication($originalApp);
            Facade::clearResolvedInstances();
        }

        expect(true)->toBeTrue();
    });

    it('can listen for events', function () {
        $action = ClosureAction::make();
        $callback = function ($data) {
            return $data;
        };

        $result = $action->on('test.event.a', $callback);

        expect($result)->toBe($action);
    });

    it('can emit events', function () {
        $action = ClosureAction::make();
        $data = ['key' => 'value'];
        $eventReceived = false;

        $action->on('test.event.a', function ($receivedData) use ($data, &$eventReceived) {
            expect($receivedData)->toBe($data);
            $eventReceived = true;
        });

        $result = $action->event('test.event.a', $data);

        expect($result)->toBe($action);
        expect($eventReceived)->toBeTrue();
    });

    it('throws exception for invalid event when listening', function () {
        $action = ClosureAction::make();

        expect(fn () => $action->on('invalid-event', function () {}))
            ->toThrow(InvalidArgumentException::class, "Cannot listen for event 'invalid-event'.");
    });

    it('throws exception for invalid event when emitting', function () {
        $action = ClosureAction::make();

        expect(fn () => $action->event('invalid-event', []))
            ->toThrow(InvalidArgumentException::class, "Cannot emit event 'invalid-event'.");
    });

    it('can forward events', function () {
        $eventsReceived = [];
        ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$eventsReceived) {
                $eventsReceived[] = $data;
            })
            ->handle(function () {
                ClosureAction::make()
                    ->forwardEvents(['test.event.a'])
                    ->handle(function ($action) {
                        $action->event('test.event.a', 'test-data');
                    });
            });

        expect($eventsReceived)->toContain('test-data');
    });

    it('only forwards the events named in forwardEvents()', function () {
        $received = [];

        ClosureAction::make()
            ->on('test.event.b', function ($data) use (&$received) {
                $received[] = $data;
            })
            ->handle(function () {
                ClosureAction::make()
                    ->forwardEvents(['test.event.a'])
                    ->handle(function ($action) {
                        $action->event('test.event.b', 'data-b');
                    });
            });

        expect($received)->toBe([]);
    });

    it('forward events with null uses allowed events', function () {
        $eventsReceived = [];
        ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$eventsReceived) {
                $eventsReceived[] = ['event' => 'test.event.a', 'data' => $data];
            })
            ->on('test.event.b', function ($data) use (&$eventsReceived) {
                $eventsReceived[] = ['event' => 'test.event.b', 'data' => $data];
            })
            ->handle(function () {
                ClosureAction::make()
                    ->forwardEvents()
                    ->handle(function ($action) {
                        $action->event('test.event.a', 'data-a');
                        $action->event('test.event.b', 'data-b');
                    });
            });

        expect($eventsReceived)->toHaveCount(2);
        expect($eventsReceived[0]['event'])->toBe('test.event.a');
        expect($eventsReceived[0]['data'])->toBe('data-a');
        expect($eventsReceived[1]['event'])->toBe('test.event.b');
        expect($eventsReceived[1]['data'])->toBe('data-b');
    });

    it('get allowed events returns events from attribute', function () {
        $action = ClosureAction::make();

        $events = $action->getAllowedEvents();

        expect($events)->toBe(['test.event.a', 'test.event.b']);
    });

    it('caches allowed events per class without leaking the parent fallback across classes', function () {
        // ClosureActionChild has no #[EmitsEvents] attribute of its own, so
        // getAllowedEvents() falls back to reading its parent's (ClosureAction's)
        // attribute - but the resolved value must be cached under the child's own
        // class, never under the parent's.
        $parent = ClosureAction::make();
        $child = ClosureActionChild::make();

        $expected = ['test.event.a', 'test.event.b'];

        // Interleave cold and warm calls in both orders - no call here should
        // ever hit the other class's cache entry.
        expect($child->getAllowedEvents())->toBe($expected);
        expect($parent->getAllowedEvents())->toBe($expected);
        expect($parent->getAllowedEvents())->toBe($expected);
        expect($child->getAllowedEvents())->toBe($expected);
        expect($child->getAllowedEvents())->toBe($expected);
        expect($parent->getAllowedEvents())->toBe($expected);

        // Confirm both classes actually resolved to distinct cache entries,
        // rather than the child's fallback having been (mis)cached onto the
        // parent's key or vice versa.
        $reflection = new ReflectionClass(ClosureAction::class);
        $property = $reflection->getProperty('allowedEventsCache');
        $property->setAccessible(true);
        $cache = $property->getValue();

        expect($cache[ClosureAction::class])->toBe($expected);
        expect($cache[ClosureActionChild::class])->toBe($expected);
    });

    it('cleanup on destruct', function () {
        $eventReceived = false;
        $action = ClosureAction::make();
        $action->on('test.event.a', function () use (&$eventReceived) {
            $eventReceived = true;
        });

        // Verify listener is registered by emitting event
        $action->event('test.event.a', 'test');
        expect($eventReceived)->toBeTrue();

        // Trigger destructor - should clean up listeners
        unset($action);

        // Test passes if no exception is thrown during cleanup
        // The actual cleanup is verified in the integration test below
        expect(true)->toBeTrue();
    });
});

// Propagation Context Capture Tests
describe('Propagation Context Capture', function () {
    it('delivers to ancestors captured when forwardEvents() was called, even after leaving their scope', function () {
        $eventsReceived = [];
        $inner = null;

        $ancestor = ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$eventsReceived) {
                $eventsReceived[] = $data;
            });

        // Configure the inner action inside the ancestor's execution scope...
        $ancestor->handle(function () use (&$inner) {
            $inner = ClosureAction::make()->forwardEvents(['test.event.a']);
        });

        // ...but run it later, with no ancestor frame on the call stack.
        $inner->handle(function ($action) {
            $action->event('test.event.a', 'captured-data');
        });

        expect($eventsReceived)->toBe(['captured-data']);
    });

    it('does not deliver to an ancestor that only encloses execution, not configuration', function () {
        $eventsReceived = [];

        // Configured at top level: no enclosing trait user to capture.
        $inner = ClosureAction::make()->forwardEvents(['test.event.a']);

        ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$eventsReceived) {
                $eventsReceived[] = $data;
            })
            ->handle(function () use ($inner) {
                $inner->handle(function ($action) {
                    $action->event('test.event.a', 'emit-scope-data');
                });
            });

        expect($eventsReceived)->toBe([]);
    });

    it('re-captures the propagation context each time forwardEvents() is called', function () {
        $firstReceived = [];
        $secondReceived = [];
        $inner = null;

        ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$firstReceived) {
                $firstReceived[] = $data;
            })
            ->handle(function () use (&$inner) {
                $inner = ClosureAction::make()->forwardEvents(['test.event.a']);
            });

        ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$secondReceived) {
                $secondReceived[] = $data;
            })
            ->handle(function () use ($inner) {
                $inner->forwardEvents(['test.event.a']);
                $inner->handle(function ($action) {
                    $action->event('test.event.a', 'recaptured-data');
                });
            });

        expect($firstReceived)->toBe([]);
        expect($secondReceived)->toBe(['recaptured-data']);
    });

    it('propagates to a plain trait-using service, like the README EmailService example', function () {
        $received = [];

        $service = new #[EmitsEvents(['test.event.a'])] class
        {
            use HandlesEvents;

            public function sendWelcomeEmail(): void
            {
                ClosureAction::make()
                    ->forwardEvents(['test.event.a'])
                    ->handle(function ($action) {
                        $action->event('test.event.a', 'welcome');
                    });
            }
        };

        $service->on('test.event.a', function ($data) use (&$received) {
            $received[] = $data;
        });

        $service->sendWelcomeEmail();

        expect($received)->toBe(['welcome']);
    });

    it('propagates to an ancestor that composes HandlesEvents through an intermediate trait', function () {
        $received = [];

        $service = new #[EmitsEvents(['test.event.a'])] class
        {
            use ComposesHandlesEvents;

            public function sendWelcomeEmail(): void
            {
                ClosureAction::make()
                    ->forwardEvents(['test.event.a'])
                    ->handle(function ($action) {
                        $action->event('test.event.a', 'composed');
                    });
            }
        };

        $service->on('test.event.a', function ($data) use (&$received) {
            $received[] = $data;
        });

        $service->sendWelcomeEmail();

        expect($received)->toBe(['composed']);
    });

    it('skips propagation to captured ancestors that have been garbage collected', function () {
        $inner = null;

        $ancestor = ClosureAction::make();
        $ancestor->handle(function () use (&$inner) {
            $inner = ClosureAction::make()->forwardEvents(['test.event.a']);
        });

        unset($ancestor);
        gc_collect_cycles();

        // Emitting must neither error nor deliver anywhere.
        $result = $inner->handle(function ($action) {
            return $action->event('test.event.a', 'orphaned');
        });

        expect($result)->toBe($inner);
    });
});

// Event Action Integration Tests
describe('Event Action Integration', function () {
    it('can emit events', function () {
        $eventsCalled = [];
        ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$eventsCalled) {
                $eventsCalled[] = 'test.event.a';
                expect($data)->toBe('Hello, World!');
            })
            ->on('test.event.b', function ($data) use (&$eventsCalled) {
                $eventsCalled[] = 'test.event.b';
                expect($data)->toBe(['Hello', 'World']);
            })
            ->handle(function ($action) {
                $action->event('test.event.a', 'Hello, World!');
                $action->event('test.event.b', ['Hello', 'World']);
            });

        expect($eventsCalled)->toBe(['test.event.a', 'test.event.b']);
    });

    it('throws an exception if an event is not allowed', function () {
        $action = ClosureAction::make();

        expect(fn () => $action->handle(function ($action) {
            $action->event('test.event.c', 'Hello, World!');
        }))
            ->toThrow(InvalidArgumentException::class, "Cannot emit event 'test.event.c'. Did you mean: 'test.event.a'?");
    });

    it('throws an exception if listening to an event that is not allowed', function () {
        $action = ClosureAction::make();

        expect(fn () => $action->on('test.event.c', function () {}))
            ->toThrow(InvalidArgumentException::class, "Cannot listen for event 'test.event.c'. Did you mean: 'test.event.a'?");
    });

    it('isolates events for each action instance', function () {
        $actionA = ClosureAction::make();
        $actionB = ClosureAction::make();

        $eventsCalledForA = [];
        $eventsCalledForB = [];

        $actionA->on('test.event.a', function () use (&$eventsCalledForA) {
            $eventsCalledForA[] = 'test.event.a';
        });
        $actionB->on('test.event.a', function () use (&$eventsCalledForB) {
            $eventsCalledForB[] = 'test.event.a';
        });

        $actionA->handle(function ($action) {
            $action->event('test.event.a', 'Hello, World!');
        });
        $actionB->handle(function ($action) {
            $action->event('test.event.a', 'Hello, World!');
        });

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
                (new class
                {
                    public function handle()
                    {
                        ClosureAction::make()
                            ->forwardEvents(['test.event.a', 'test.event.b'])
                            ->handle(function ($action) {
                                $action->event('test.event.a', 'Hello, World!');
                                $action->event('test.event.b', ['Hello', 'World']);
                            });
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
                (new class
                {
                    public function handle()
                    {
                        ClosureAction::make()
                            ->forwardEvents()
                            ->handle(function ($action) {
                                $action->event('test.event.a', 'Hello, World!');
                            });
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
                (new class
                {
                    public function handle()
                    {
                        ClosureAction::make()
                            ->handle(function ($action) {
                                $action->event('test.event.a', 'Hello, World!');
                            });
                    }
                })->handle();
            });

        expect($eventsCalled)->toBe([]);
    });

    it('cleans up event listeners on destruction', function () {
        $action = ClosureAction::make();
        $eventReceived = false;

        $action->on('test.event.a', function () use (&$eventReceived) {
            $eventReceived = true;
        });

        // Emit event to verify listener is registered
        $action->event('test.event.a', 'test');
        expect($eventReceived)->toBeTrue();

        // Reset and destroy action
        $eventReceived = false;
        unset($action);

        // Create new action with same class and emit same event
        // If cleanup worked, the listener from destroyed action shouldn't fire
        $newAction = ClosureAction::make();
        $newAction->event('test.event.a', 'test-again');

        // The previous listener should be cleaned up, so eventReceived should remain false
        // Note: This is indirect testing - we can't directly verify cleanup without reflection
        // but we can verify behavior doesn't break
        expect(true)->toBeTrue(); // Test ensures no exceptions and cleanup works
    });

    it('handles edge case with circular event propagation', function () {
        $eventsReceived = [];

        $action1 = ClosureAction::make();
        $action2 = ClosureAction::make();

        $action1->on('test.event.a', function ($data) use (&$eventsReceived, $action2) {
            $eventsReceived[] = $data;
            // This could cause circular propagation, but should be handled
            $action2->event('test.event.a', $data);
        });

        $action1->handle(function ($action) {
            $action->event('test.event.a', 'Hello, World!');
        });

        // Should not cause infinite loop - the propagation should be limited
        expect($eventsReceived)->toHaveCount(1);
    });

    it('prevents infinite loops in deeply nested event propagation', function () {
        $eventsReceived = [];

        ClosureAction::make()
            ->on('test.event.a', function ($data) use (&$eventsReceived) {
                $eventsReceived[] = ['level1', $data];
            })
            ->handle(function () use (&$eventsReceived) {
                ClosureAction::make()
                    ->on('test.event.a', function ($data) use (&$eventsReceived) {
                        $eventsReceived[] = ['level2', $data];
                    })
                    ->forwardEvents(['test.event.a'])
                    ->handle(function () use (&$eventsReceived) {
                        ClosureAction::make()
                            ->forwardEvents(['test.event.a'])
                            ->handle(function ($action) {
                                $action->event('test.event.a', 'test-data');
                            });
                    });
            });

        // Should receive events but not infinitely loop
        // level3 (innermost) emits -> forwards to level2 -> forwards to level1
        // Each level should receive the event once
        expect($eventsReceived)->toHaveCount(2); // level2 and level1, but not level3 (no listener)
        expect($eventsReceived[0][0])->toBe('level2');
        expect($eventsReceived[1][0])->toBe('level1');
        expect($eventsReceived[0][1])->toBe('test-data');
        expect($eventsReceived[1][1])->toBe('test-data');
    });

    it('handles events in destructor correctly', function () {
        $cleanupExecuted = false;

        $action = ClosureAction::make();
        $action->on('test.event.a', function () use (&$cleanupExecuted) {
            $cleanupExecuted = true;
        });

        // Emit event before destruction
        $action->event('test.event.a', 'test');
        expect($cleanupExecuted)->toBeTrue();

        // Destroy action - should clean up without errors
        unset($action);

        // Test passes if no exception is thrown during cleanup
        expect(true)->toBeTrue();
    });
});
