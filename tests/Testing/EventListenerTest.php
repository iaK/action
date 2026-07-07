<?php

use Iak\Action\Action;
use Iak\Action\Testing\EventListener;
use Iak\Action\Testing\Results\EmittedEvent;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\EnumEventAction;
use Iak\Action\Tests\TestClasses\OrderEvent;

describe('EventListener', function () {
    it('records events emitted while listening', function () {
        $action = ClosureAction::make();
        $listener = new EventListener($action);

        $result = $listener->listen(function () use ($action) {
            $action->event('test.event.a', ['key' => 'value']);

            return 'done';
        });

        expect($result)->toBe('done')
            ->and($listener->getEvents())->toHaveCount(1);

        $event = $listener->getEvents()[0];

        expect($event)->toBeInstanceOf(EmittedEvent::class)
            ->and($event->name)->toBe('test.event.a')
            ->and($event->data)->toBe(['key' => 'value'])
            ->and($event->action)->toBe(ClosureAction::class);
    });

    it('ignores events outside the listening window', function () {
        $action = ClosureAction::make();
        $listener = new EventListener($action);

        $action->event('test.event.a', 'before');
        $listener->listen(fn () => null);
        $action->event('test.event.a', 'after');

        expect($listener->getEvents())->toBeEmpty();
    });

    it('records nothing for actions without allowed events', function () {
        // An anonymous action with no #[EmitsEvents] attribute
        $action = new class extends Action
        {
            public function handle(?Closure $closure = null)
            {
                return $closure ? $closure($this) : null;
            }
        };

        $listener = new EventListener($action);

        expect($listener->listen(fn () => 'ok'))->toBe('ok')
            ->and($listener->getEvents())->toBeEmpty();
    });

    it('does not keep a dropped listener recording', function () {
        $action = ClosureAction::make();
        $listener = new EventListener($action);
        $reference = WeakReference::create($listener);

        unset($listener);
        gc_collect_cycles();

        // The dispatcher closure holds only a WeakReference: once the
        // listener is gone, emitting is a harmless no-op.
        $action->event('test.event.a', 'late');

        expect($reference->get())->toBeNull();
    });

    it('matches enum cases through is()', function () {
        $action = EnumEventAction::make();
        $listener = new EventListener($action);

        $listener->listen(fn () => $action->event(OrderEvent::Placed, null));

        $event = $listener->getEvents()[0];

        expect($event->is(OrderEvent::Placed))->toBeTrue()
            ->and($event->is('order.placed'))->toBeTrue()
            ->and($event->is(OrderEvent::Shipped))->toBeFalse();
    });
});
