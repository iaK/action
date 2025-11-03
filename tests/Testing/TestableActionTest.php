<?php

use Iak\Action\Testing\Results\Memory;
use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\LogAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

describe('Testable', function () {
    it('can create testable action with callback', function () {
        $callbackExecuted = false;
        $testable = ClosureAction::test(function ($testable) use (&$callbackExecuted) {
            $callbackExecuted = true;
            expect($testable)->toBeInstanceOf(Testable::class);
        });

        expect($callbackExecuted)->toBeTrue();
        expect($testable)->toBeInstanceOf(Testable::class);
    });

    describe('mocking', function () {
        it('can mock actions inside other actions', function () {
            ClosureAction::test()
                ->only(ClosureAction::class)
                ->handle(function () {
                    ClosureAction::make()->handle();
                    OtherClosureAction::make()->handle();
                });

            expect(OtherClosureAction::make())
                ->tobeinstanceof(MockInterface::class);
        });

        it('can use without method with string class', function () {
            ClosureAction::test()
                ->without(OtherClosureAction::class)
                ->handle();

            expect(OtherClosureAction::make())->toBeInstanceOf(MockInterface::class);
        });

        it('can use without method with array of classes', function () {
            ClosureAction::test()
                ->without([OtherClosureAction::class, ClosureAction::class])
                ->handle(function () {
                    OtherClosureAction::make()->handle();
                    ClosureAction::make()->handle();
                });

            // Both classes should be mocked when resolved
            expect(OtherClosureAction::make())->toBeInstanceOf(MockInterface::class);
            expect(ClosureAction::make())->toBeInstanceOf(MockInterface::class);
        });

        it('can use without method and specify return value', function () {
            $result = ClosureAction::test()
                ->without([OtherClosureAction::class => 'Mocked hello, World!'])
                ->handle(function () {
                    return OtherClosureAction::make()->handle();
                });

            expect($result)->toBe('Mocked hello, World!');
        });

        it('can use without method and specify return value for several actions', function () {
            $result = ClosureAction::test()
                ->without([
                    // Not nested
                    ClosureAction::class => 'Mocked hello, World!',
                    // Nested
                    [OtherClosureAction::class => 'Mocked again!'],
                ])
                ->handle(function () {
                    return ClosureAction::make()->handle().' '.OtherClosureAction::make()->handle();
                });

            expect($result)->toBe('Mocked hello, World! Mocked again!');
        });

        it('can use only method with array parameter', function () {
            ClosureAction::test()
                ->only([ClosureAction::class, OtherClosureAction::class])
                ->handle(function () {
                    LogAction::make()->handle();
                    OtherClosureAction::make()->handle();
                    ClosureAction::make()->handle();
                });

            expect(LogAction::make())->toBeInstanceOf(MockInterface::class);
            expect(OtherClosureAction::make())->toBeInstanceOf(OtherClosureAction::class);
            expect(ClosureAction::make())->toBeInstanceOf(ClosureAction::class);
        });

        it('throws exception when without method receives invalid parameter', function () {
            expect(fn () => ClosureAction::test()->without(123))
                ->toThrow(Exception::class);
        });
    });

    describe('feature combinations', function () {
        describe('on parent action', function () {
            it('can combine profile and queries on parent action', function () {
                $result = ClosureAction::test()
                    ->profile(function ($profiles) {
                        expect($profiles)->toHaveCount(1);
                        expect($profiles[0]->class)->toBe(ClosureAction::class);
                        expect($profiles[0]->memoryRecords)->toHaveCount(1);
                        expect($profiles[0]->memoryRecords[0])->toBeInstanceOf(Memory::class);
                        expect($profiles[0]->memoryRecords[0]->name)->toBe('start');
                    })
                    ->queries(function ($queries) {
                        expect($queries)->toHaveCount(1);
                        expect($queries[0]->query)->toBe('SELECT 1');
                    })
                    ->handle(function ($action) {
                        $action->recordMemory('start');
                        DB::statement('SELECT 1');
                    });
            });

            it('can combine profile and logs on parent action', function () {
                ClosureAction::test()
                    ->profile(function ($profiles) {
                        expect($profiles)->toHaveCount(1);
                        expect($profiles[0]->class)->toBe(ClosureAction::class);
                        expect($profiles[0]->memoryRecords)->toHaveCount(1);
                        expect($profiles[0]->memoryRecords[0])->toBeInstanceOf(Memory::class);
                        expect($profiles[0]->memoryRecords[0]->name)->toBe('start');
                    })
                    ->logs(function ($logs) {
                        expect($logs)->toHaveCount(1);
                        expect($logs[0]->message)->toBe('Test message');
                    })
                    ->handle(function ($action) {
                        $action->recordMemory('start');
                        Log::info('Test message');
                    });
            });

            it('can combine queries and logs on parent action', function () {
                ClosureAction::test()
                    ->queries(function ($queries) {
                        expect($queries)->toHaveCount(1);
                        expect($queries[0]->query)->toBe('SELECT 1');
                    })
                    ->logs(function ($logs) {
                        expect($logs)->toHaveCount(1);
                        expect($logs[0]->message)->toBe('Test message');
                    })
                    ->handle(function ($action) {
                        DB::statement('SELECT 1');
                        Log::info('Test message');
                    });
            });

            it('can combine all three methods on parent action', function () {
                ClosureAction::test()
                    ->profile(function ($profiles) {
                        expect($profiles)->toHaveCount(1);
                        expect($profiles[0]->class)->toBe(ClosureAction::class);
                        expect($profiles[0]->memoryRecords)->toHaveCount(1);
                        expect($profiles[0]->memoryRecords[0])->toBeInstanceOf(Memory::class);
                        expect($profiles[0]->memoryRecords[0]->name)->toBe('start');
                    })
                    ->queries(function ($queries) {
                        expect($queries)->toHaveCount(1);
                        expect($queries[0]->query)->toBe('SELECT 1');
                    })
                    ->logs(function ($logs) {
                        expect($logs)->toHaveCount(1);
                        expect($logs[0]->message)->toBe('Test message');
                    })
                    ->handle(function ($action) {
                        $action->recordMemory('start');
                        DB::statement('SELECT 1');
                        Log::info('Test message');
                    });
            });
        });

        describe('on nested actions', function () {
            it('can combine profile and queries on nested actions', function () {
                ClosureAction::test()
                    ->profile([ClosureAction::class], function ($profiles) {
                        expect($profiles)->toHaveCount(1);
                        expect($profiles[0]->class)->toBe(ClosureAction::class);
                        expect($profiles[0]->memoryRecords)->toHaveCount(1);
                        expect($profiles[0]->memoryRecords[0])->toBeInstanceOf(Memory::class);
                        expect($profiles[0]->memoryRecords[0]->name)->toBe('start');
                    })
                    ->queries([ClosureAction::class], function ($queries) {
                        expect($queries)->toHaveCount(1);
                        expect($queries[0]->query)->toBe('SELECT 1');
                    })
                    ->handle(function () {
                        ClosureAction::make()->handle(function ($action) {
                            $action->recordMemory('start');
                            DB::statement('SELECT 1');
                        });
                    });
            });

            it('can combine profile and logs on nested actions', function () {
                ClosureAction::test()
                    ->profile([LogAction::class], function ($profiles) use (&$capturedProfiles) {
                        expect($profiles)->toHaveCount(1);
                        expect($profiles[0]->class)->toBe(LogAction::class);
                    })
                    ->logs([LogAction::class], function ($logs) use (&$capturedLogs) {
                        expect($logs)->toHaveCount(1);
                        expect($logs[0]->message)->toBe('Composite action logging');
                    })
                    ->handle(function () {
                        LogAction::make()->handle('Composite action logging');

                        return 'done';
                    });
            });

            it('can combine queries and logs on nested actions', function () {
                ClosureAction::test()
                    ->queries([ClosureAction::class], function ($queries) {
                        expect($queries)->toHaveCount(1);
                        expect($queries[0]->query)->toBe('SELECT 1');
                    })
                    ->logs([LogAction::class], function ($logs) {
                        expect($logs)->toHaveCount(1);
                        expect($logs[0]->message)->toBe('Test log');
                    })
                    ->handle(function () {
                        ClosureAction::make()->handle(function () {
                            DB::statement('SELECT 1');
                        });
                        LogAction::make()->handle('Test log');
                    });
            });

            it('can combine all three methods on nested actions', function () {
                $capturedProfiles = [];
                $capturedQueries = [];
                $capturedLogs = [];

                ClosureAction::test()
                    ->profile([ClosureAction::class, LogAction::class], function ($profiles) use (&$capturedProfiles) {
                        expect($profiles)->toHaveCount(2);
                        expect($profiles[0]->class)->toBe(ClosureAction::class);
                        expect($profiles[1]->class)->toBe(LogAction::class);
                    })
                    ->queries([ClosureAction::class], function ($queries) use (&$capturedQueries) {
                        expect($queries)->toHaveCount(1);
                        expect($queries[0]->query)->toBe('SELECT 1');
                    })
                    ->logs([LogAction::class], function ($logs) use (&$capturedLogs) {
                        expect($logs)->toHaveCount(1);
                        expect($logs[0]->message)->toBe('Test log');
                    })
                    ->handle(function () {
                        ClosureAction::make()->handle(function () {
                            DB::statement('SELECT 1');
                        });
                        LogAction::make()->handle('Test log');
                    });
            });
        });
    });
});
