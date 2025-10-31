<?php

use Iak\Action\Testing\Testable;
use Iak\Action\Testing\Results\Measurement;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;

describe('Measurement Feature', function () {
    it('can measure the duration of an action', function () {
        $result = ClosureAction::test()
        ->measure(function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[0]->start)->toBeLessThan($measurements[0]->end);
            expect($measurements[0]->duration()->totalMilliseconds)->toBeGreaterThan(0);
            expect($measurements[0])->toBeInstanceOf(Measurement::class);
        })
        ->handle(function () {
            usleep(1000);

            return 'done';
        });

        expect($result)->toBe('done');
    });

    it('can measure the duration of an action with a specific action', function ($actions) {
        $result = ClosureAction::test()
        ->measure($actions, function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0])->toBeInstanceOf(Measurement::class);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
        })
        ->handle(function () {
            ClosureAction::make()->handle();

            return 'done';
        });

        expect($result)->toBe('done');
        })->with([
        'asString' => [ClosureAction::class], 
        'asArray' => [[ClosureAction::class]]
    ]);

    it('can measure several actions', function () {
        ClosureAction::test()
        ->measure([ClosureAction::class, OtherClosureAction::class], function (array $measurements) {
            expect($measurements)->toHaveCount(2);
            expect($measurements[0]->class)->toBe(ClosureAction::class); // Executed first
            expect($measurements[1]->class)->toBe(OtherClosureAction::class);   // Executed second
        })
        ->handle(function () {
            ClosureAction::make()->handle();
            OtherClosureAction::make()->handle();
        });
    });

    it('can convert measurement to string', function () {
        ClosureAction::test()
        ->measure(function (array $measurements) {
            $measurement = $measurements[0];
            expect((string) $measurement)->toBe("{$measurement->class} took {$measurement->duration()->totalMilliseconds}ms (memory: {$measurement->memoryUsed()}, peak: {$measurement->peakMemory()})");
        })
        ->handle();
    });

    it('can measure memory usage of an action', function () {
        $result = ClosureAction::test()
        ->measure(function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            $measurement = $measurements[0];
            expect($measurement->class)->toBe(ClosureAction::class);
            expect($measurement->startMemory)->toBeInt();
            expect($measurement->endMemory)->toBeInt();
            expect($measurement->peakMemory)->toBeInt();
            expect($measurement->endMemory)->toBeGreaterThanOrEqual($measurement->startMemory);
            expect($measurement->peakMemory)->toBeGreaterThanOrEqual($measurement->endMemory);
        })
        ->handle(function () {
            // Allocate some memory
            $data = str_repeat('x', 1024 * 512); // 512KB string
            return strlen($data);
        });

        expect($result)->toBe(1024 * 512);
    });

    it('can measure memory usage of multiple actions', function () {
        ClosureAction::test()
        ->measure([ClosureAction::class, OtherClosureAction::class], function (array $measurements) {
            expect($measurements)->toHaveCount(2);
            
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[1]->class)->toBe(OtherClosureAction::class);
        })
        ->handle(function () {
            ClosureAction::make()->handle();
            OtherClosureAction::make()->handle();
        });
    });

    it('includes memory info in measurement string representation when memory is used', function () {
        ClosureAction::test()
        ->measure(function (array $measurements) {
            $measurement = $measurements[0];
            $string = (string) $measurement;
            expect($string)->toContain('took');
            expect($string)->toContain('ms');
            
            // Memory info should be included if memory was actually used
            if ($measurement->memoryUsed('B') > 0 || $measurement->peakMemory > 0) {
                expect($string)->toContain('memory:');
                expect($string)->toContain('peak:');
            }
        })
        ->handle(function () {
            // Allocate some memory to ensure memory tracking is active
            $data = str_repeat('x', 1024 * 100); // 100KB string
            return strlen($data);
        });
    });

    it('can access memory records through measurement', function () {
        ClosureAction::test()
        ->measure(function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->records()[0]['name'])->toBe('start');
            expect($measurements[0]->records()[1]['name'])->toBe('end');
        })
        ->handle(function ($action) {
            $action->recordMemory('start');
            $action->recordMemory('end');
        });
        });

    it('can access memory records through measurement on the provided action', function () {
        $result = ClosureAction::test()
        ->measure(ClosureAction::class, function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            $measurement = $measurements[0];
            $records = $measurement->records();
            expect($records[0]['name'])->toBe('start');
        })
        ->handle(function () {
            ClosureAction::make()->handle(function ($action) {
                $action->recordMemory('start');
            });
            return 'done';
        });

        expect($result)->toBe('done');
        });

    it('throws exception when measure method receives invalid callback', function () {
        expect(fn () => ClosureAction::test()->measure(ClosureAction::class))
            ->toThrow(InvalidArgumentException::class, 'A callback is required');
    });

    it('throws exception when measure method receives invalid class', function () {
        expect(fn () => ClosureAction::test()->measure('NonExistentClass', function () {}))
            ->toThrow(Exception::class);
    });
});
