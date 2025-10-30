<?php

use Iak\Action\Testing\Results\Measurement;
use Carbon\CarbonInterval;

it('can create measurement', function () {
    $start = 1000.0;
    $end = 1001.5;
    $measurement = new Measurement('TestClass', $start, $end);
    
    expect($measurement->class)->toBe('TestClass');
    expect($measurement->start)->toBe($start);
    expect($measurement->end)->toBe($end);
});

it('can calculate duration', function () {
    $start = 1000.0;
    $end = 1001.5;
    $measurement = new Measurement('TestClass', $start, $end);
    
    $duration = $measurement->duration();
    
    expect($duration)->toBeInstanceOf(CarbonInterval::class);
    expect($duration->totalMilliseconds)->toBe(1500.0);
});

it('has string representation', function () {
    $start = 1000.0;
    $end = 1001.5;
    $measurement = new Measurement('TestClass', $start, $end);
    
    $expected = 'TestClass took 1500ms';
    expect((string) $measurement)->toBe($expected);
});

it('calculates duration with zero time', function () {
    $start = 1000.0;
    $end = 1000.0;
    $measurement = new Measurement('TestClass', $start, $end);
    
    $duration = $measurement->duration();
    
    expect($duration->totalMilliseconds)->toBe(0.0);
});
