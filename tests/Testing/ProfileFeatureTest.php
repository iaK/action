<?php

use Iak\Action\Testing\Results\Profile;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;

describe('Profile Feature', function () {
    it('can profile the duration of an action', function () {
        $result = ClosureAction::test()
            ->profile(function (array $profiles) {
                expect($profiles)->toHaveCount(1);
                expect($profiles[0]->class)->toBe(ClosureAction::class);
                expect($profiles[0]->start)->toBeLessThan($profiles[0]->end);
                expect($profiles[0]->duration()->totalMilliseconds)->toBeGreaterThan(0);
                expect($profiles[0])->toBeInstanceOf(Profile::class);
            })
            ->handle(function () {
                usleep(1000);

                return 'done';
            });

        expect($result)->toBe('done');
    });

    it('can profile the duration of an action with a specific action', function ($actions) {
        $result = ClosureAction::test()
            ->profile($actions, function (array $profiles) {
                expect($profiles)->toHaveCount(1);
                expect($profiles[0])->toBeInstanceOf(Profile::class);
                expect($profiles[0]->class)->toBe(ClosureAction::class);
            })
            ->handle(function () {
                ClosureAction::make()->handle();

                return 'done';
            });

        expect($result)->toBe('done');
    })->with([
        'asString' => [ClosureAction::class],
        'asArray' => [[ClosureAction::class]],
    ]);

    it('can profile several actions', function () {
        ClosureAction::test()
            ->profile([ClosureAction::class, OtherClosureAction::class], function (array $profiles) {
                expect($profiles)->toHaveCount(2);
                expect($profiles[0]->class)->toBe(ClosureAction::class); // Executed first
                expect($profiles[1]->class)->toBe(OtherClosureAction::class);   // Executed second
            })
            ->handle(function () {
                ClosureAction::make()->handle();
                OtherClosureAction::make()->handle();
            });
    });

    it('can convert profile to string', function () {
        ClosureAction::test()
            ->profile(function (array $profiles) {
                $profile = $profiles[0];
                expect((string) $profile)->toBe("{$profile->class} took {$profile->duration()->totalMilliseconds}ms (memory: {$profile->memoryUsed()}, peak: {$profile->peakMemory()})");
            })
            ->handle();
    });

    it('can profile memory usage of an action', function () {
        $result = ClosureAction::test()
            ->profile(function (array $profiles) {
                expect($profiles)->toHaveCount(1);
                $profile = $profiles[0];
                expect($profile->class)->toBe(ClosureAction::class);
                expect($profile->startMemory)->toBeInt();
                expect($profile->endMemory)->toBeInt();
                expect($profile->peakMemory)->toBeInt();
                expect($profile->endMemory)->toBeGreaterThanOrEqual($profile->startMemory);
                expect($profile->peakMemory)->toBeGreaterThanOrEqual($profile->endMemory);
            })
            ->handle(function () {
                // Allocate some memory
                $data = str_repeat('x', 1024 * 512); // 512KB string

                return strlen($data);
            });

        expect($result)->toBe(1024 * 512);
    });

    it('can profile memory usage of multiple actions', function () {
        ClosureAction::test()
            ->profile([ClosureAction::class, OtherClosureAction::class], function (array $profiles) {
                expect($profiles)->toHaveCount(2);

                expect($profiles[0]->class)->toBe(ClosureAction::class);
                expect($profiles[1]->class)->toBe(OtherClosureAction::class);
            })
            ->handle(function () {
                ClosureAction::make()->handle();
                OtherClosureAction::make()->handle();
            });
    });

    it('includes memory info in profile string representation when memory is used', function () {
        ClosureAction::test()
            ->profile(function (array $profiles) {
                $profile = $profiles[0];
                $string = (string) $profile;
                expect($string)->toContain('took');
                expect($string)->toContain('ms');

                // Memory info should be included if memory was actually used
                if ($profile->memoryUsed('B') > 0 || $profile->peakMemory > 0) {
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

    it('can access memory records through profile', function () {
        ClosureAction::test()
            ->profile(function (array $profiles) {
                expect($profiles)->toHaveCount(1);
                expect($profiles[0]->records()[0]->name)->toBe('start');
                expect($profiles[0]->records()[1]->name)->toBe('end');
            })
            ->handle(function ($action) {
                $action->recordMemory('start');
                $action->recordMemory('end');
            });
    });

    it('can access memory records through profile on the provided action', function () {
        $result = ClosureAction::test()
            ->profile(ClosureAction::class, function (array $profiles) {
                expect($profiles)->toHaveCount(1);
                $profile = $profiles[0];
                $records = $profile->records();
                expect($records[0]->name)->toBe('start');
            })
            ->handle(function () {
                ClosureAction::make()->handle(function ($action) {
                    $action->recordMemory('start');
                });

                return 'done';
            });

        expect($result)->toBe('done');
    });

    it('throws exception when profile method receives invalid callback', function () {
        expect(fn () => ClosureAction::test()->profile(ClosureAction::class))
            ->toThrow(InvalidArgumentException::class, 'A callback is required');
    });

    it('throws exception when profile method receives invalid class', function () {
        expect(fn () => ClosureAction::test()->profile('NonExistentClass', function () {}))
            ->toThrow(Exception::class);
    });
});
