<?php

use Iak\Action\Testing\LogListener;
use Iak\Action\Testing\Results\Entry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

describe('LogListener', function () {
    it('can create log listener', function () {
        $listener = new LogListener();
        
        expect($listener)->toBeInstanceOf(LogListener::class);
        expect($listener->isEnabled())->toBeFalse();
        });

    it('can listen for logs', function () {
        $listener = new LogListener();
        
        $result = $listener->listen(function () {
            Log::info('Test message', ['key' => 'value']);
            return 'test result';
        });

        expect($result)->toBe('test result');
        expect($listener->isEnabled())->toBeFalse();
        });

    it('captures logs during listening', function () {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Test message', ['key' => 'value']);
            Log::warning('Warning message');
        });

        $logs = $listener->getLogs();
        
        expect($logs)->toHaveCount(2);
        expect($logs[0])->toBeInstanceOf(Entry::class);
        expect($logs[0]->level)->toBe('INFO');
        expect($logs[0]->message)->toBe('Test message');
        expect($logs[0]->context)->toBe(['key' => 'value']);
        });

    it('can get log count', function () {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Message 1');
            Log::info('Message 2');
        });

        expect($listener->getLogCount())->toBe(2);
        });

    it('can get logs by level', function () {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Info message');
            Log::warning('Warning message');
            Log::info('Another info message');
        });

        $infoLogs = $listener->getLogsByLevel('INFO');
        $warningLogs = $listener->getLogsByLevel('WARNING');
        
        expect($infoLogs)->toHaveCount(2);
        expect($warningLogs)->toHaveCount(1);
        });

    it('can clear logs', function () {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Test message');
        });

        expect($listener->getLogCount())->toBe(1);
        
        $listener->clear();
        
        expect($listener->getLogCount())->toBe(0);
        });

    it('can get handler', function () {
        $listener = new LogListener();
        
        $handler = $listener->getHandler();
        
        expect($handler)->toBeInstanceOf(\Monolog\Handler\AbstractHandler::class);
        });
});
