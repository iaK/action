<?php

use Iak\Action\Testing\RuntimeMeasurer;
use Iak\Action\Testing\Measurement;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\MultiArgAction;

it('can be instantiated with an action', function () {
    $action = new SayHelloAction();
    $measurer = new RuntimeMeasurer($action);

    expect($measurer)->toBeInstanceOf(RuntimeMeasurer::class);
});

it('handles action execution and measures time', function () {
    $action = new SayHelloAction();
    $measurer = new RuntimeMeasurer($action);

    $result = $measurer->handle();

    expect($result)->toBe('Hello, World!');
    expect($measurer->start)->toBeString();
    expect($measurer->end)->toBeString();
    expect((float) $measurer->end)->toBeGreaterThanOrEqual((float) $measurer->start);
});

it('handles action with arguments', function () {
    $action = new ClosureAction();
    $measurer = new RuntimeMeasurer($action);

    $result = $measurer->handle(function () {
        return 'Custom result';
    });

    expect($result)->toBe('Custom result');
    expect($measurer->start)->toBeString();
    expect($measurer->end)->toBeString();
});

it('handles action with multiple arguments', function () {
    $action = new MultiArgAction();
    $measurer = new RuntimeMeasurer($action);

    $result = $measurer->handle('Hello', 'World', 'Test');

    expect($result)->toBe('Hello World Test');
    expect($measurer->start)->toBeString();
    expect($measurer->end)->toBeString();
});

it('creates measurement result with correct class name', function () {
    $action = new SayHelloAction();
    $measurer = new RuntimeMeasurer($action);

    $measurer->handle();
    $measurement = $measurer->result();

    expect($measurement)->toBeInstanceOf(Measurement::class);
    expect($measurement->class)->toBe(SayHelloAction::class);
    expect($measurement->start)->toBe((float) $measurer->start);
    expect($measurement->end)->toBe((float) $measurer->end);
});

it('measures execution time accurately', function () {
    $action = new ClosureAction();
    $measurer = new RuntimeMeasurer($action);

    // Create a closure that sleeps for a specific duration
    $measurer->handle(function () {
        usleep(10000); // Sleep for 10ms
    });

    $measurement = $measurer->result();
    $durationMs = $measurement->duration()->totalMilliseconds;

    expect($durationMs)->toBeGreaterThan(5); // Should be at least 5ms
    expect($durationMs)->toBeLessThan(50);   // Should be less than 50ms
});

it('handles action that throws exception', function () {
    $action = new ClosureAction();
    $measurer = new RuntimeMeasurer($action);

    expect(function () use ($measurer) {
        $measurer->handle(function () {
            throw new Exception('Test exception');
        });
    })->toThrow(Exception::class, 'Test exception');

    // Start time should be recorded even if exception is thrown
    expect($measurer->start)->toBeString();
    // End time might not be set if exception occurs before it's assigned
    expect(isset($measurer->end))->toBeFalse();
});
