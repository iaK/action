<?php

use Iak\Action\Testing\LogListener;
use Iak\Action\Testing\Results\Entry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

class LogListenerTest extends TestCase
{
    public function test_can_create_log_listener()
    {
        $listener = new LogListener();
        
        $this->assertInstanceOf(LogListener::class, $listener);
        $this->assertFalse($listener->isEnabled());
    }

    public function test_can_listen_for_logs()
    {
        $listener = new LogListener();
        
        $result = $listener->listen(function () {
            Log::info('Test message', ['key' => 'value']);
            return 'test result';
        });

        $this->assertEquals('test result', $result);
        $this->assertFalse($listener->isEnabled());
    }

    public function test_captures_logs_during_listening()
    {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Test message', ['key' => 'value']);
            Log::warning('Warning message');
        });

        $logs = $listener->getLogs();
        
        $this->assertCount(2, $logs);
        $this->assertInstanceOf(Entry::class, $logs[0]);
        $this->assertEquals('INFO', $logs[0]->level);
        $this->assertEquals('Test message', $logs[0]->message);
        $this->assertEquals(['key' => 'value'], $logs[0]->context);
    }

    public function test_can_get_log_count()
    {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Message 1');
            Log::info('Message 2');
        });

        $this->assertEquals(2, $listener->getLogCount());
    }

    public function test_can_get_logs_by_level()
    {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Info message');
            Log::warning('Warning message');
            Log::info('Another info message');
        });

        $infoLogs = $listener->getLogsByLevel('INFO');
        $warningLogs = $listener->getLogsByLevel('WARNING');
        
        $this->assertCount(2, $infoLogs);
        $this->assertCount(1, $warningLogs);
    }

    public function test_can_clear_logs()
    {
        $listener = new LogListener();
        
        $listener->listen(function () {
            Log::info('Test message');
        });

        $this->assertEquals(1, $listener->getLogCount());
        
        $listener->clear();
        
        $this->assertEquals(0, $listener->getLogCount());
    }

    public function test_can_get_handler()
    {
        $listener = new LogListener();
        
        $handler = $listener->getHandler();
        
        $this->assertInstanceOf(\Monolog\Handler\AbstractHandler::class, $handler);
    }
}
