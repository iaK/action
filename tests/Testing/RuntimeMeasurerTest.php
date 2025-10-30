<?php

use Iak\Action\Testing\RuntimeMeasurer;
use Iak\Action\Testing\Results\Measurement;
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

it('handles action execution and measures memory', function () {
    $action = new SayHelloAction();
    $measurer = new RuntimeMeasurer($action);

    $result = $measurer->handle();

    expect($result)->toBe('Hello, World!');
    expect($measurer->startMemory)->toBeInt();
    expect($measurer->endMemory)->toBeInt();
    expect($measurer->peakMemory)->toBeInt();
    expect($measurer->endMemory)->toBeGreaterThanOrEqual($measurer->startMemory);
    expect($measurer->peakMemory)->toBeGreaterThanOrEqual($measurer->endMemory);
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

it('creates measurement result with memory tracking', function () {
    $action = new SayHelloAction();
    $measurer = new RuntimeMeasurer($action);

    $measurer->handle();
    $measurement = $measurer->result();

    expect($measurement)->toBeInstanceOf(Measurement::class);
    expect($measurement->class)->toBe(SayHelloAction::class);
    expect($measurement->start)->toBe((float) $measurer->start);
    expect($measurement->end)->toBe((float) $measurer->end);
    expect($measurement->startMemory)->toBe($measurer->startMemory);
    expect($measurement->endMemory)->toBe($measurer->endMemory);
    expect($measurement->peakMemory)->toBe($measurer->peakMemory);
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

it('measures memory usage for memory-intensive action', function () {
    $action = new ClosureAction();
    $measurer = new RuntimeMeasurer($action);

    $result = $measurer->handle(function () {
        // Allocate some memory
        $data = str_repeat('x', 1024 * 1024); // 1MB string
        return strlen($data);
    });

    expect($result)->toBe(1024 * 1024);
    expect($measurer->startMemory)->toBeInt();
    expect($measurer->endMemory)->toBeInt();
    expect($measurer->peakMemory)->toBeInt();
    
    // Memory usage should be tracked (end memory may not always be greater due to GC)
    expect($measurer->endMemory)->toBeGreaterThanOrEqual($measurer->startMemory);
    expect($measurer->peakMemory)->toBeGreaterThanOrEqual($measurer->endMemory);
    
    $measurement = $measurer->result();
    // Memory used might be 0 or positive depending on GC
    expect($measurement->memoryUsed('B'))->toBeGreaterThanOrEqual(0);
    expect($measurement->memoryUsed())->toMatch('/\d+\.?\d*\s+(B|KB|MB|GB|TB)/');
});

it('handles memory tracking when action throws exception', function () {
    $action = new ClosureAction();
    $measurer = new RuntimeMeasurer($action);

    expect(function () use ($measurer) {
        $measurer->handle(function () {
            // Allocate some memory before throwing
            $data = str_repeat('x', 1024 * 100); // 100KB string
            throw new Exception('Test exception');
        });
    })->toThrow(Exception::class, 'Test exception');

    // Memory should still be tracked even if exception is thrown
    expect($measurer->startMemory)->toBeInt();
    // End memory might not be set if exception occurs before it's assigned
    expect(isset($measurer->endMemory))->toBeFalse();
    expect(isset($measurer->peakMemory))->toBeFalse();
});

it('can record memory at specific points', function () {
    $action = new ClosureAction();
    $measurer = new RuntimeMeasurer($action);

    $result = $measurer->handle(function () use ($measurer) {
        $measurer->recordMemory('start');
        
        // Allocate some memory
        $data1 = str_repeat('x', 1024 * 100); // 100KB
        $measurer->recordMemory('after_first_allocation');
        
        // Allocate more memory
        $data2 = str_repeat('y', 1024 * 200); // 200KB
        $measurer->recordMemory('after_second_allocation');
        
        return strlen($data1) + strlen($data2);
    });

    expect($result)->toBe(1024 * 300);
    expect($measurer->memoryRecords)->toHaveCount(3);
    
    // Check records
    expect($measurer->memoryRecords[0]['name'])->toBe('start');
    expect($measurer->memoryRecords[0]['memory'])->toBeInt();
    expect($measurer->memoryRecords[0]['timestamp'])->toBeFloat();
    
    expect($measurer->memoryRecords[1]['name'])->toBe('after_first_allocation');
    expect($measurer->memoryRecords[1]['memory'])->toBeInt();
    expect($measurer->memoryRecords[1]['memory'])->toBeGreaterThanOrEqual($measurer->memoryRecords[0]['memory']);
    
    expect($measurer->memoryRecords[2]['name'])->toBe('after_second_allocation');
    expect($measurer->memoryRecords[2]['memory'])->toBeInt();
    expect($measurer->memoryRecords[2]['memory'])->toBeGreaterThanOrEqual($measurer->memoryRecords[1]['memory']);
});

it('creates measurement result with memory records', function () {
    $action = new ClosureAction();
    $measurer = new RuntimeMeasurer($action);

    $measurer->handle(function () use ($measurer) {
        $measurer->recordMemory('test_point');
        return 'test';
    });

    $measurement = $measurer->result();
    
    expect($measurement)->toBeInstanceOf(Measurement::class);
    expect($measurement->memoryRecords)->toHaveCount(1);
    expect($measurement->memoryRecords[0]['name'])->toBe('test_point');
    expect($measurement->memoryRecords[0]['memory'])->toBeInt();
    expect($measurement->memoryRecords[0]['timestamp'])->toBeFloat();
    
    $records = $measurement->records();
    expect($records)->toHaveCount(1);
    expect($records[0]['name'])->toBe('test_point');
    expect($records[0]['memory'])->toBeInt();
    expect($records[0]['memory_formatted'])->toBeString();
    expect($records[0]['timestamp'])->toBeFloat();
    expect($records[0]['relative_time'])->toBeFloat();
});
