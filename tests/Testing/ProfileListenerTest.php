<?php

use Iak\Action\Testing\ProfileListener;
use Iak\Action\Testing\Results\Profile;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\LogAction;

describe('ProfileListener', function () {
    it('can be instantiated with an action', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        expect($profiler)->toBeInstanceOf(ProfileListener::class);
    });

    it('handles action execution and profiles time', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        $result = $profiler->handle(function () {
            return 'Hello, World!';
        });

        expect($result)->toBe('Hello, World!');
        expect($profiler->getProfile()->start)->toBeFloat();
        expect($profiler->getProfile()->end)->toBeFloat();
        expect((float) $profiler->getProfile()->end)->toBeGreaterThanOrEqual((float) $profiler->getProfile()->start);
    });

    it('handles action execution and profiles memory', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        $result = $profiler->handle(function () {
            return 'Hello, World!';
        });

        expect($result)->toBe('Hello, World!');
        expect($profiler->getProfile()->startMemory)->toBeInt();
        expect($profiler->getProfile()->endMemory)->toBeInt();
        expect($profiler->getProfile()->peakMemory)->toBeInt();
        expect($profiler->getProfile()->endMemory)->toBeGreaterThanOrEqual($profiler->getProfile()->startMemory);
        expect($profiler->getProfile()->peakMemory)->toBeGreaterThanOrEqual($profiler->getProfile()->endMemory);
    });

    it('handles action with arguments', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        $result = $profiler->handle(function () {
            return 'Custom result';
        });

        expect($result)->toBe('Custom result');
        expect($profiler->getProfile()->start)->toBeFloat();
        expect($profiler->getProfile()->end)->toBeFloat();
    });

    it('handles action with multiple arguments', function () {
        $action = new LogAction;
        $profiler = new ProfileListener($action);
        $result = $profiler->handle('Hello', ['test' => 'test'], 'info');

        expect($result)->toBe('Hello');
        expect($profiler->getProfile()->start)->toBeFloat();
        expect($profiler->getProfile()->end)->toBeFloat();
    });

    it('creates profile result with correct class name', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        $profiler->handle();
        $profile = $profiler->getProfile();

        expect($profile)->toBeInstanceOf(Profile::class);
        expect($profile->class)->toBe(ClosureAction::class);
    });

    it('creates profile result with memory tracking', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        $profiler->handle();
        $profile = $profiler->getProfile();

        expect($profile)->toBeInstanceOf(Profile::class);
        expect($profile->class)->toBe(ClosureAction::class);
    });

    it('profiles execution time accurately', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        // Create a closure that sleeps for a specific duration
        $profiler->handle(function () {
            usleep(10000); // Sleep for 10ms
        });

        $profile = $profiler->getProfile();
        $durationMs = $profile->duration()->totalMilliseconds;

        expect($durationMs)->toBeGreaterThan(5); // Should be at least 5ms
        expect($durationMs)->toBeLessThan(50);   // Should be less than 50ms
    });

    it('handles action that throws exception', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        expect(function () use ($profiler) {
            $profiler->handle(function () {
                throw new Exception('Test exception');
            });
        })->toThrow(Exception::class, 'Test exception');

        expect(fn () => $profiler->getProfile())->toThrow(Exception::class, 'Action has not been executed yet');
    });

    it('profiles memory usage for memory-intensive action', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        $result = $profiler->handle(function () {
            // Allocate some memory
            $data = str_repeat('x', 1024 * 1024); // 1MB string

            return strlen($data);
        });

        expect($result)->toBe(1024 * 1024);
        expect($profiler->getProfile()->startMemory)->toBeInt();
        expect($profiler->getProfile()->endMemory)->toBeInt();
        expect($profiler->getProfile()->peakMemory)->toBeInt();

        // Memory usage should be tracked (end memory may not always be greater due to GC)
        expect($profiler->getProfile()->endMemory)->toBeGreaterThanOrEqual($profiler->getProfile()->startMemory);
        expect($profiler->getProfile()->peakMemory)->toBeGreaterThanOrEqual($profiler->getProfile()->endMemory);

        $profile = $profiler->getProfile();
        // Memory used might be 0 or positive depending on GC
        expect($profile->memoryUsed('B'))->toBeGreaterThanOrEqual(0);
        expect($profile->memoryUsed())->toMatch('/\d+\.?\d*\s+(B|KB|MB|GB|TB)/');
    });

    it('handles memory tracking when action throws exception', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        expect(function () use ($profiler) {
            $profiler->handle(function () {
                // Allocate some memory before throwing
                $data = str_repeat('x', 1024 * 100); // 100KB string
                throw new Exception('Test exception');
            });
        })->toThrow(Exception::class, 'Test exception');

        expect(fn () => $profiler->getProfile())->toThrow(Exception::class, 'Action has not been executed yet');
    });

    it('can record memory at specific points', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

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
        expect($profiler->getProfile()->memoryRecords)->toHaveCount(3);

        // Check records
        expect($profiler->getProfile()->memoryRecords[0]->name)->toBe('start');
        expect($profiler->getProfile()->memoryRecords[0]->memory)->toBeInt();
        expect($profiler->getProfile()->memoryRecords[0]->timestamp)->toBeFloat();

        expect($profiler->getProfile()->memoryRecords[1]->name)->toBe('after_first_allocation');
        expect($profiler->getProfile()->memoryRecords[1]->memory)->toBeInt();
        expect($profiler->getProfile()->memoryRecords[1]->memory)->toBeGreaterThanOrEqual($profiler->getProfile()->memoryRecords[0]->memory);

        expect($profiler->getProfile()->memoryRecords[2]->name)->toBe('after_second_allocation');
        expect($profiler->getProfile()->memoryRecords[2]->memory)->toBeInt();
        expect($profiler->getProfile()->memoryRecords[2]->memory)->toBeGreaterThanOrEqual($profiler->getProfile()->memoryRecords[1]->memory);
    });

    it('creates profile result with memory records', function () {
        $action = new ClosureAction;
        $profiler = new ProfileListener($action);

        $profiler->handle(function () use ($profiler) {
            $profiler->recordMemory('test_point');

            return 'test';
        });

        $profile = $profiler->getProfile();

        expect($profile)->toBeInstanceOf(Profile::class);
        expect($profile->memoryRecords)->toHaveCount(1);
        expect($profile->memoryRecords[0]->name)->toBe('test_point');
        expect($profile->memoryRecords[0]->memory)->toBeInt();
        expect($profile->memoryRecords[0]->timestamp)->toBeFloat();

        $records = $profile->records();
        expect($records)->toHaveCount(1);
        expect($records[0]->name)->toBe('test_point');
        expect($records[0]->memory)->toBeInt();
        expect($records[0]->formattedMemory())->toBeString();
        expect($records[0]->timestamp)->toBeFloat();
    });
});
