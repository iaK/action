<?php

use Carbon\Carbon;
use Iak\Action\Testing\InMemoryLogHandler;
use Iak\Action\Testing\LogListener;
use Illuminate\Support\Facades\Log;
use Monolog\LogRecord;
use Monolog\Level;

describe('InMemoryLogHandler', function () {
    it('can create handler', function () {
        $handler = new InMemoryLogHandler;

        expect($handler)->toBeInstanceOf(InMemoryLogHandler::class);
    });

    it('can set listener', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        // Use reflection to verify listener was set
        $reflection = new \ReflectionClass($handler);
        $property = $reflection->getProperty('listener');
        $property->setAccessible(true);

        expect($property->getValue($handler))->toBe($listener);
    });

    it('returns false when listener is not set', function () {
        $handler = new InMemoryLogHandler;

        $record = new LogRecord(
            Carbon::now()->toDateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            []
        );

        $result = $handler->handle($record);

        expect($result)->toBeFalse();
    });

    it('returns false when listener is disabled', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        // Listener is disabled by default
        $record = new LogRecord(
            Carbon::now()->toDateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            []
        );

        $result = $handler->handle($record);

        expect($result)->toBeFalse();
        expect($listener->getLogCount())->toBe(0);
    });

    it('captures log when listener is enabled', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        $timestamp = Carbon::now();
        $record = new LogRecord(
            $timestamp->toDateTimeImmutable(),
            'test-channel',
            Level::Info,
            'Test message',
            ['key' => 'value']
        );

        // Enable listener
        $listener->listen(function () use ($handler, $record) {
            $result = $handler->handle($record);

            expect($result)->toBeFalse();
        });

        $logs = $listener->getLogs();

        expect($logs)->toHaveCount(1);
        expect($logs[0]->level)->toBe('INFO');
        expect($logs[0]->message)->toBe('Test message');
        expect($logs[0]->context)->toBe(['key' => 'value']);
        expect($logs[0]->channel)->toBe('test-channel');
    });

    it('handles different log levels', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        $timestamp = Carbon::now();

        $levels = [
            Level::Debug,
            Level::Info,
            Level::Notice,
            Level::Warning,
            Level::Error,
            Level::Critical,
            Level::Alert,
            Level::Emergency,
        ];

        $listener->listen(function () use ($handler, $levels, $timestamp) {
            foreach ($levels as $level) {
                $record = new LogRecord(
                    $timestamp->toDateTimeImmutable(),
                    'test',
                    $level,
                    "Message for {$level->getName()}",
                    []
                );

                $handler->handle($record);
            }
        });

        $logs = $listener->getLogs();

        expect($logs)->toHaveCount(8);
        expect($logs[0]->level)->toBe('DEBUG');
        expect($logs[1]->level)->toBe('INFO');
        expect($logs[2]->level)->toBe('NOTICE');
        expect($logs[3]->level)->toBe('WARNING');
        expect($logs[4]->level)->toBe('ERROR');
        expect($logs[5]->level)->toBe('CRITICAL');
        expect($logs[6]->level)->toBe('ALERT');
        expect($logs[7]->level)->toBe('EMERGENCY');
    });

    it('captures timestamp correctly', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        $timestamp = Carbon::create(2023, 1, 15, 14, 30, 45);
        $record = new LogRecord(
            $timestamp->toDateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            []
        );

        $listener->listen(function () use ($handler, $record) {
            $handler->handle($record);
        });

        $logs = $listener->getLogs();

        expect($logs)->toHaveCount(1);
        expect($logs[0]->timestamp->format('Y-m-d H:i:s'))->toBe('2023-01-15 14:30:45');
    });

    it('captures context data', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        $context = [
            'user_id' => 123,
            'action' => 'login',
            'nested' => ['key' => 'value'],
        ];

        $record = new LogRecord(
            Carbon::now()->toDateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            $context
        );

        $listener->listen(function () use ($handler, $record) {
            $handler->handle($record);
        });

        $logs = $listener->getLogs();

        expect($logs)->toHaveCount(1);
        expect($logs[0]->context)->toBe($context);
    });

    it('does not interfere with normal logging', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        $record = new LogRecord(
            Carbon::now()->toDateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            []
        );

        // handle() returns false, meaning it doesn't actually log
        $result = $handler->handle($record);

        expect($result)->toBeFalse();
    });

    it('handles empty context', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        $record = new LogRecord(
            Carbon::now()->toDateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            []
        );

        $listener->listen(function () use ($handler, $record) {
            $handler->handle($record);
        });

        $logs = $listener->getLogs();

        expect($logs)->toHaveCount(1);
        expect($logs[0]->context)->toBe([]);
    });

    it('handles different channels', function () {
        $handler = new InMemoryLogHandler;
        $listener = new LogListener;

        $handler->setListener($listener);

        $channels = ['app', 'security', 'payment', 'api'];

        $listener->listen(function () use ($handler, $channels) {
            foreach ($channels as $channel) {
                $record = new LogRecord(
                    Carbon::now()->toDateTimeImmutable(),
                    $channel,
                    Level::Info,
                    "Message for {$channel}",
                    []
                );

                $handler->handle($record);
            }
        });

        $logs = $listener->getLogs();

        expect($logs)->toHaveCount(4);
        expect($logs[0]->channel)->toBe('app');
        expect($logs[1]->channel)->toBe('security');
        expect($logs[2]->channel)->toBe('payment');
        expect($logs[3]->channel)->toBe('api');
    });
});

