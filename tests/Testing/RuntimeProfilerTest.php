<?php

use Iak\Action\Testing\RuntimeProfiler;
use Iak\Action\Testing\Results\Profile;
use Iak\Action\Tests\TestClasses\LogAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\MultiArgAction;

describe('RuntimeProfiler', function () {
    it('can be instantiated with an action', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        expect($profiler)->toBeInstanceOf(RuntimeProfiler::class);
        });

    it('handles action execution and profiles time', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $result = $profiler->handle(function () {
            return 'Hello, World!';
        });

        expect($result)->toBe('Hello, World!');
        expect($profiler->start)->toBeString();
        expect($profiler->end)->toBeString();
        expect((float) $profiler->end)->toBeGreaterThanOrEqual((float) $profiler->start);
        });

    it('handles action execution and profiles memory', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $result = $profiler->handle(function () {
            return 'Hello, World!';
        });

        expect($result)->toBe('Hello, World!');
        expect($profiler->startMemory)->toBeInt();
        expect($profiler->endMemory)->toBeInt();
        expect($profiler->peakMemory)->toBeInt();
        expect($profiler->endMemory)->toBeGreaterThanOrEqual($profiler->startMemory);
        expect($profiler->peakMemory)->toBeGreaterThanOrEqual($profiler->endMemory);
        });

    it('handles action with arguments', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $result = $profiler->handle(function () {
            return 'Custom result';
        });

        expect($result)->toBe('Custom result');
        expect($profiler->start)->toBeString();
        expect($profiler->end)->toBeString();
        });

    it('handles action with multiple arguments', function () {
        $action = new LogAction();
        $profiler = new RuntimeProfiler($action);
        $result = $profiler->handle('Hello', ['test' => 'test'], 'info');

        expect($result)->toBe('Hello');
        expect($profiler->start)->toBeString();
        expect($profiler->end)->toBeString();
        });

    it('creates profile result with correct class name', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $profiler->handle();
        $profile = $profiler->result();

        expect($profile)->toBeInstanceOf(Profile::class);
        expect($profile->class)->toBe(ClosureAction::class);
        expect($profile->start)->toBe((float) $profiler->start);
        expect($profile->end)->toBe((float) $profiler->end);
        });

    it('creates profile result with memory tracking', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $profiler->handle();
        $profile = $profiler->result();

        expect($profile)->toBeInstanceOf(Profile::class);
        expect($profile->class)->toBe(ClosureAction::class);
        expect($profile->start)->toBe((float) $profiler->start);
        expect($profile->end)->toBe((float) $profiler->end);
        expect($profile->startMemory)->toBe($profiler->startMemory);
        expect($profile->endMemory)->toBe($profiler->endMemory);
        expect($profile->peakMemory)->toBe($profiler->peakMemory);
        });

    it('profiles execution time accurately', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        // Create a closure that sleeps for a specific duration
        $profiler->handle(function () {
            usleep(10000); // Sleep for 10ms
        });

        $profile = $profiler->result();
        $durationMs = $profile->duration()->totalMilliseconds;

        expect($durationMs)->toBeGreaterThan(5); // Should be at least 5ms
        expect($durationMs)->toBeLessThan(50);   // Should be less than 50ms
        });

    it('handles action that throws exception', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        expect(function () use ($profiler) {
            $profiler->handle(function () {
                throw new Exception('Test exception');
            });
        })->toThrow(Exception::class, 'Test exception');

        // Start time should be recorded even if exception is thrown
        expect($profiler->start)->toBeString();
        // End time might not be set if exception occurs before it's assigned
        expect(isset($profiler->end))->toBeFalse();
        });

    it('profiles memory usage for memory-intensive action', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $result = $profiler->handle(function () {
            // Allocate some memory
            $data = str_repeat('x', 1024 * 1024); // 1MB string
            return strlen($data);
        });

        expect($result)->toBe(1024 * 1024);
        expect($profiler->startMemory)->toBeInt();
        expect($profiler->endMemory)->toBeInt();
        expect($profiler->peakMemory)->toBeInt();
        
        // Memory usage should be tracked (end memory may not always be greater due to GC)
        expect($profiler->endMemory)->toBeGreaterThanOrEqual($profiler->startMemory);
        expect($profiler->peakMemory)->toBeGreaterThanOrEqual($profiler->endMemory);
        
        $profile = $profiler->result();
        // Memory used might be 0 or positive depending on GC
        expect($profile->memoryUsed('B'))->toBeGreaterThanOrEqual(0);
        expect($profile->memoryUsed())->toMatch('/\d+\.?\d*\s+(B|KB|MB|GB|TB)/');
        });

    it('handles memory tracking when action throws exception', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        expect(function () use ($profiler) {
            $profiler->handle(function () {
                // Allocate some memory before throwing
                $data = str_repeat('x', 1024 * 100); // 100KB string
                throw new Exception('Test exception');
            });
        })->toThrow(Exception::class, 'Test exception');

        // Memory should still be tracked even if exception is thrown
        expect($profiler->startMemory)->toBeInt();
        // End memory might not be set if exception occurs before it's assigned
        expect(isset($profiler->endMemory))->toBeFalse();
        expect(isset($profiler->peakMemory))->toBeFalse();
        });

    it('can record memory at specific points', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $result = $profiler->handle(function () use ($profiler) {
            $profiler->recordMemory('start');
            
            // Allocate some memory
            $data1 = str_repeat('x', 1024 * 100); // 100KB
            $profiler->recordMemory('after_first_allocation');
            
            // Allocate more memory
            $data2 = str_repeat('y', 1024 * 200); // 200KB
            $profiler->recordMemory('after_second_allocation');
            
            return strlen($data1) + strlen($data2);
        });

        expect($result)->toBe(1024 * 300);
        expect($profiler->memoryRecords)->toHaveCount(3);
        
        // Check records
        expect($profiler->memoryRecords[0]['name'])->toBe('start');
        expect($profiler->memoryRecords[0]['memory'])->toBeInt();
        expect($profiler->memoryRecords[0]['timestamp'])->toBeFloat();
        
        expect($profiler->memoryRecords[1]['name'])->toBe('after_first_allocation');
        expect($profiler->memoryRecords[1]['memory'])->toBeInt();
        expect($profiler->memoryRecords[1]['memory'])->toBeGreaterThanOrEqual($profiler->memoryRecords[0]['memory']);
        
        expect($profiler->memoryRecords[2]['name'])->toBe('after_second_allocation');
        expect($profiler->memoryRecords[2]['memory'])->toBeInt();
        expect($profiler->memoryRecords[2]['memory'])->toBeGreaterThanOrEqual($profiler->memoryRecords[1]['memory']);
        });

    it('creates profile result with memory records', function () {
        $action = new ClosureAction();
        $profiler = new RuntimeProfiler($action);

        $profiler->handle(function () use ($profiler) {
            $profiler->recordMemory('test_point');
            return 'test';
        });

        $profile = $profiler->result();
        
        expect($profile)->toBeInstanceOf(Profile::class);
        expect($profile->memoryRecords)->toHaveCount(1);
        expect($profile->memoryRecords[0]['name'])->toBe('test_point');
        expect($profile->memoryRecords[0]['memory'])->toBeInt();
        expect($profile->memoryRecords[0]['timestamp'])->toBeFloat();
        
        $records = $profile->records();
        expect($records)->toHaveCount(1);
        expect($records[0]['name'])->toBe('test_point');
        expect($records[0]['memory'])->toBeInt();
        expect($records[0]['memory_formatted'])->toBeString();
        expect($records[0]['timestamp'])->toBeFloat();
        expect($records[0]['relative_time'])->toBeFloat();
        });
});

