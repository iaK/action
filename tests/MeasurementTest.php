<?php

use Carbon\CarbonInterval;
use Iak\Action\Measurement;

it('can be instantiated with class name and timestamps', function () {
    $measurement = new Measurement('TestClass', 1.0, 2.0);

    expect($measurement->class)->toBe('TestClass');
    expect($measurement->start)->toBe(1.0);
    expect($measurement->end)->toBe(2.0);
});

it('can be instantiated with object class', function () {
    $object = new stdClass();
    $measurement = new Measurement($object, 1.0, 2.0);

    expect($measurement->class)->toBe($object);
    expect($measurement->start)->toBe(1.0);
    expect($measurement->end)->toBe(2.0);
});

it('calculates duration correctly', function () {
    $measurement = new Measurement('TestClass', 1.0, 2.0);
    $duration = $measurement->duration();

    expect($duration)->toBeInstanceOf(CarbonInterval::class);
    expect($duration->totalMilliseconds)->toBe(1000.0);
});


it('handles zero duration', function () {
    $measurement = new Measurement('TestClass', 1.0, 1.0);
    $duration = $measurement->duration();

    expect($duration->totalMilliseconds)->toBe(0.0);
});

it('converts to string correctly', function () {
    $measurement = new Measurement('TestClass', 1.0, 2.0);
    $string = (string) $measurement;

    expect($string)->toBe('TestClass took 1000ms');
});

it('converts to string with fractional milliseconds', function () {
    $measurement = new Measurement('TestClass', 0.001, 0.0015);
    $string = (string) $measurement;

    expect($string)->toBe('TestClass took 0.5ms');
});

it('converts to string with zero duration', function () {
    $measurement = new Measurement('TestClass', 1.0, 1.0);
    $string = (string) $measurement;

    expect($string)->toBe('TestClass took 0ms');
});

it('converts to string with negative duration', function () {
    $measurement = new Measurement('TestClass', 2.0, 1.0);
    $string = (string) $measurement;

    expect($string)->toBe('TestClass took -1000ms');
});

it('preserves float precision in duration calculation', function () {
    $measurement = new Measurement('TestClass', 1.0, 1.000333);
    $duration = $measurement->duration();

    expect($duration->totalMilliseconds)->toBeGreaterThan(0.33);
    expect($duration->totalMilliseconds)->toBeLessThan(0.34);
});

