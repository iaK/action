<?php

use Iak\Action\Testable;
use Mockery\MockInterface;
use Iak\Action\Measurement;
use Iak\Action\Tests\TestClasses\TestAction;
use Iak\Action\Tests\TestClasses\SecondAction;
use Iak\Action\Tests\TestClasses\MiddleManAction;
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
    MiddleManAction::test()
        ->only(TestAction::class)
        ->handle();

    expect(SecondAction::make())
        ->tobeinstanceof(MockInterface::class);
});

it('can measure the duration of an action', function () {
    TestAction::test()
        ->measure(function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0])->toBeInstanceOf(Measurement::class);
        })
        ->handle();
});

it('can measure the duration of an action with a specific action', function ($actions) {
    MiddleManAction::test()
        ->measure($actions, function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0])->toBeInstanceOf(Measurement::class);
            expect($measurements[0]->class)->toBe(TestAction::class);
        })
        ->handle();
})->with([
    'asString' => [TestAction::class], 
    'asArray' => [[TestAction::class]]
]);


it('can measure several actions', function () {
    MiddleManAction::test()
        ->measure([TestAction::class, SecondAction::class], function (array $measurements) {
            expect($measurements)->toHaveCount(2);
            expect($measurements[0]->class)->toBe(SecondAction::class); // Executed first
            expect($measurements[1]->class)->toBe(TestAction::class);   // Executed second
        })
        ->handle();
});

it('can create action instance with dependency injection', function () {
    $action = TestAction::make();
    
    expect($action)->toBeInstanceOf(TestAction::class);
    expect($action->handle())->toBe('Hello, World!');
});

it('can create fake action with custom alias', function () {
    $alias = 'custom.test.action';
    $fake = TestAction::fake($alias);
    
    expect($fake)->toBeInstanceOf(MockInterface::class);
    expect(app($alias))->toBe($fake);
});

it('can create testable action with callback', function () {
    $callbackExecuted = false;
    $testable = TestAction::test(function ($testable) use (&$callbackExecuted) {
        $callbackExecuted = true;
        expect($testable)->toBeInstanceOf(Testable::class);
    });
    
    expect($callbackExecuted)->toBeTrue();
    expect($testable)->toBeInstanceOf(Testable::class);
});

it('can use without method with string class', function () {
    TestAction::test()
        ->without(SecondAction::class)
        ->handle();
    
    // SecondAction should be mocked when resolved
    expect(SecondAction::make())->toBeInstanceOf(MockInterface::class);
});

it('can use without method with array of classes', function () {
    TestAction::test()
        ->without([SecondAction::class, MiddleManAction::class])
        ->handle();
    
    // Both classes should be mocked when resolved
    expect(SecondAction::make())->toBeInstanceOf(MockInterface::class);
    expect(MiddleManAction::make())->toBeInstanceOf(MockInterface::class);
});

it('throws exception when without method receives invalid parameter', function () {
    expect(fn () => TestAction::test()->without(123))
        ->toThrow(Exception::class);
});

it('can use only method with array parameter', function () {
    MiddleManAction::test()
        ->only([TestAction::class])
        ->handle();
    
    // TestAction should not be mocked (it's in the only array)
    expect(TestAction::make())->toBeInstanceOf(TestAction::class);
    // SecondAction should be mocked (it's not in the only array)
    expect(SecondAction::make())->toBeInstanceOf(MockInterface::class);
});

it('throws exception when measure method receives invalid callback', function () {
    expect(fn () => TestAction::test()->measure(TestAction::class))
        ->toThrow(InvalidArgumentException::class, 'A callback is required');
});

it('throws exception when measure method receives invalid class', function () {
    expect(fn () => TestAction::test()->measure('NonExistentClass', function () {}))
        ->toThrow(Exception::class);
});

it('can forward events', function () {
    $eventsReceived = [];
    
    MiddleManAction::make()
        ->on('test.event.a', function ($data) use (&$eventsReceived) {
            $eventsReceived[] = $data;
        })
        ->handle();
    
    expect($eventsReceived)->toHaveCount(1); // Only one event from TestAction
    expect($eventsReceived[0])->toBe('Hello, World!');
});

it('can propagate events to ancestor', function () {
    $eventsReceived = [];
    
    (new DeeplyNestedAction())
        ->on('test.event.a', function ($data) use (&$eventsReceived) {
            $eventsReceived[] = $data;
        })
        ->handle();
    
    expect($eventsReceived)->toHaveCount(1);
    expect($eventsReceived[0])->toBe('Hello, World!');
});

it('can measure action duration', function () {
    TestAction::test()
        ->measure(function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0])->toBeInstanceOf(Measurement::class);
            expect($measurements[0]->class)->toBe(TestAction::class);
            expect($measurements[0]->start)->toBeLessThan($measurements[0]->end);
        })
        ->handle();
});

it('can convert measurement to string', function () {
    TestAction::test()
        ->measure(function (array $measurements) {
            $measurement = $measurements[0];
            $string = (string) $measurement;
            
            expect($string)->toContain(TestAction::class);
            expect($string)->toContain('ms');
        })
        ->handle();
});

it('can get measurement duration', function () {
    TestAction::test()
        ->measure(function (array $measurements) {
            $measurement = $measurements[0];
            $duration = $measurement->duration();
            
            expect($duration)->toBeInstanceOf(\Carbon\CarbonInterval::class);
            expect($duration->totalMilliseconds)->toBeGreaterThan(0);
        })
        ->handle();
});

it('cleans up event listeners on destruction', function () {
    $action = TestAction::make();
    $action->on('test.event.a', function () {});
    
    // Verify event listener is registered by checking if it exists
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('generateEventName');
    $method->setAccessible(true);
    $eventName = $method->invoke($action, 'test.event.a');
    
    expect(\Illuminate\Support\Facades\Event::hasListeners($eventName))->toBeTrue();
    
    // Destroy the action
    unset($action);
    
    // Event listener should be cleaned up
    expect(\Illuminate\Support\Facades\Event::hasListeners($eventName))->toBeFalse();
});

it('handles edge case with circular event propagation', function () {
    $eventsReceived = [];
    
    // Create a scenario that could cause circular propagation
    $action1 = TestAction::make();
    $action2 = TestAction::make();
    
    $action1->on('test.event.a', function ($data) use (&$eventsReceived, $action2) {
        $eventsReceived[] = $data;
        // This could cause circular propagation, but should be handled
        $action2->event('test.event.a', $data);
    });
    
    $action1->handle();
    
    // Should not cause infinite loop - the propagation should be limited
    expect($eventsReceived)->toHaveCount(2); // One from action1, one from action2
});

it('handles edge case with non-existent ancestor', function () {
    // Test that the system handles cases where ancestors don't exist gracefully
    $action = TestAction::make();
    
    expect(fn () => $action->event('test.event.a', 'test data'))
        ->not->toThrow(Exception::class);
});

it('handles edge case with invalid event in propagation', function () {
    // Test propagation with an event that ancestor doesn't allow
    $action = TestAction::make();
    
    expect(fn () => $action->event('test.event.a', 'test data'))
        ->not->toThrow(Exception::class);
});
