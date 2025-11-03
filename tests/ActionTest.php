<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

describe('Action', function () {
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

    it('resolves action from container', function () {
        $action1 = ClosureAction::make();
        $action2 = ClosureAction::make();

        // Both should be instances but may be different objects
        expect($action1)->toBeInstanceOf(ClosureAction::class);
        expect($action2)->toBeInstanceOf(ClosureAction::class);
    });

    it('can record memory at specific points', function () {
        Event::fake();

        $action = ClosureAction::make();

        // Record memory should dispatch an event
        $action->recordMemory('test-point');

        $eventName = 'action.record_memory.'.spl_object_hash($action);
        Event::assertDispatched($eventName, function ($event, $data) {
            return $data[0] === 'test-point';
        });
    });

    it('creates testable instance', function () {
        $testable = ClosureAction::test();

        expect($testable)->toBeInstanceOf(\Iak\Action\Testing\Testable::class);
    });

    it('creates testable instance with callback', function () {
        $callbackExecuted = false;
        $testable = ClosureAction::test(function ($testable) use (&$callbackExecuted) {
            $callbackExecuted = true;
            expect($testable)->toBeInstanceOf(\Iak\Action\Testing\Testable::class);
        });

        expect($callbackExecuted)->toBeTrue();
        expect($testable)->toBeInstanceOf(\Iak\Action\Testing\Testable::class);
    });

    it('handles action bound with alias correctly', function () {
        app()->bind('custom.action', ClosureAction::class);

        $fake = ClosureAction::fake('custom.action');
        expect($fake)->toBeInstanceOf(MockInterface::class);
        expect(app('custom.action'))->toBe($fake);
    });
});
