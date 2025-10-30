<?php

use Carbon\Carbon;
use Iak\Action\Testing\Results\Entry;
use Illuminate\Support\Facades\Log;
use Iak\Action\Tests\TestClasses\LogAction;
use Iak\Action\Tests\TestClasses\ClosureAction;

it('can record logs for the calling action', function () {
    $result = ClosureAction::test()
        ->logs(function (array $logs) {
            expect($logs)->toHaveCount(4);
            
            expect($logs)->each->toBeInstanceOf(Entry::class);
            
            expect($logs[0]->level)->toBe('INFO');
            expect($logs[0]->message)->toBe('Action started');
            expect($logs[0]->context)->toBe([]);
            
            expect($logs[1]->level)->toBe('WARNING');
            expect($logs[1]->message)->toBe('This is a warning message');
            expect($logs[1]->context)->toBe(['context' => 'warning']);
            
            expect($logs[2]->level)->toBe('ERROR');
            expect($logs[2]->message)->toBe('This is an error message');
            expect($logs[2]->context)->toBe(['context' => 'error']);
            
            expect($logs[3]->level)->toBe('INFO');
            expect($logs[3]->message)->toBe('Action completed');
            expect($logs[3]->context)->toBe(['context' => 'info']);
        })
        ->handle(function () {
            Log::info('Action started');
            Log::warning('This is a warning message', ['context' => 'warning']);
            Log::error('This is an error message', ['context' => 'error']);
            Log::info('Action completed', ['context' => 'info']);

            return 'Hello from LogAction';
        });

    expect($result)->toBe('Hello from LogAction');
});

it('can record logs for a single action', function () {
    $result = ClosureAction::test()
        ->logs(ClosureAction::class, function (array $logs) {
            expect($logs)->toHaveCount(2);
            
            expect($logs[0])->toBeInstanceOf(Entry::class);
            expect($logs[0]->level)->toBe('INFO');
            expect($logs[0]->message)->toBe('Closure action started');
            
            expect($logs[1])->toBeInstanceOf(Entry::class);
            expect($logs[1]->level)->toBe('INFO');
            expect($logs[1]->message)->toBe('Closure action completed');
        })
        ->handle(function () {
            LogAction::make()->handle();
            return ClosureAction::make()->handle(function () {
                Log::info('Closure action started');
                Log::info('Closure action completed');
                return 'done';
            });
        });

    expect($result)->toBe('done');
});


it('can convert log entry to string', function () {
    ClosureAction::test()
        ->logs(function (array $logs) {
            $logEntry = $logs[0];
            $string = (string) $logEntry;

            expect($string)->toMatch('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] testing\.INFO: Action started {"context":"test"}$/');
        })
        ->handle(function () {
            Log::info('Action started', ['context' => 'test']);
        });
});


it('can record logs with different channels', function () {
    $result = ClosureAction::test()
        ->logs(function (array $logs) {
            expect($logs)->toHaveCount(2);
            
            // All logs should have the same channel (testing in test environment)
            foreach ($logs as $log) {
                expect($log->channel)->toBe('testing');
            }
        })
        ->handle(function () {
            Log::info('Default channel log');
            Log::warning('Another default channel log');
            return 'done';
        });

    expect($result)->toBe('done');
});

it('can record logs with timestamps', function () {    
    ClosureAction::test()
        ->logs(function (array $logs) {
            expect($logs[0]->timestamp)->toBeInstanceOf(Carbon::class);
        })
        ->handle(function () {
            Log::info('Action started');
            return 'done';
        });
});

it('throws exception when logs method receives invalid callback', function () {
    expect(fn () => ClosureAction::test()->logs(LogAction::class))
        ->toThrow(InvalidArgumentException::class, 'A callback is required');
});

it('throws exception when logs method receives invalid class', function () {
    expect(fn () => ClosureAction::test()->logs('NonExistentClass', function () {}))
        ->toThrow(Exception::class);
});

it('can record logs for multiple actions', function () {
    ClosureAction::test()
        ->logs([LogAction::class, ClosureAction::class], function (array $logs) {
            expect($logs)->toHaveCount(2);
            expect($logs[0]->level)->toBe('INFO');
            expect($logs[0]->message)->toBe('First log');
            expect($logs[0]->context)->toBe(['context' => 'test']);
            expect($logs[1]->level)->toBe('INFO');
            expect($logs[1]->message)->toBe('Second log');
            expect($logs[1]->context)->toBe(['context' => 'test']);
        })
        ->handle(function () {
            LogAction::make()->handle('First log', ['context' => 'test'], 'info');
            ClosureAction::make()->handle(function () {
                Log::info('Second log', ['context' => 'test']);
            });
        });
});

