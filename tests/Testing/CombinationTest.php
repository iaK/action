<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\DatabaseAction;
use Iak\Action\Tests\TestClasses\FireEventAction;
use Iak\Action\Tests\TestClasses\LogAction;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\TestMemoryAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('can combine measure and queries on parent action', function () {
    $result = ClosureAction::test()
        ->measure(function ($measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[0]->memoryRecords)->toHaveCount(1);
            expect($measurements[0]->memoryRecords[0]['name'])->toBe('start');
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

it('can combine measure and logs on parent action', function () {    
    ClosureAction::test()
        ->measure(function ($measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[0]->memoryRecords)->toHaveCount(1);
            expect($measurements[0]->memoryRecords[0]['name'])->toBe('start');
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
        ->measure(function ($measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[0]->memoryRecords)->toHaveCount(1);
            expect($measurements[0]->memoryRecords[0]['name'])->toBe('start');
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

it('can combine measure and queries on nested actions', function () {
    ClosureAction::test()
        ->measure([ClosureAction::class], function ($measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[0]->memoryRecords)->toHaveCount(1);
            expect($measurements[0]->memoryRecords[0]['name'])->toBe('start');
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

it('can combine measure and logs on nested actions', function () {
    ClosureAction::test()
        ->measure([LogAction::class], function ($measurements) use (&$capturedMeasurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->class)->toBe(LogAction::class);
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
    $capturedMeasurements = [];
    $capturedQueries = [];
    $capturedLogs = [];
    
    ClosureAction::test()
        ->measure([ClosureAction::class, LogAction::class], function ($measurements) use (&$capturedMeasurements) {
            expect($measurements)->toHaveCount(2);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[1]->class)->toBe(LogAction::class);
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
