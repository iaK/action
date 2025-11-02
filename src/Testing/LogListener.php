<?php

namespace Iak\Action\Testing;

use Carbon\Carbon;
use Monolog\Logger;
use Monolog\LogRecord;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractHandler;
use Iak\Action\Testing\Results\Entry;

class LogListener implements Listener
{
    protected bool $enabled = false;
    protected array $logs = [];
    protected $handler;
    protected $originalHandlers = [];
    protected ?string $action;

    public function __construct(?string $action = null)
    {
        $this->action = $action;
        // Create a custom handler that captures logs
        $this->handler = new class extends AbstractHandler {
            private $listener;

            public function setListener(LogListener $listener): void
            {
                $this->listener = $listener;
            }

            public function handle(LogRecord $record): bool
            {
                if (!$this->listener || !$this->listener->isEnabled()) {
                    return false;
                }

                $this->listener->addLog(
                    $record->level->getName(),
                    $record->message,
                    $record->context,
                    Carbon::createFromTimestamp($record->datetime->getTimestamp()),
                    $record->channel
                );

                return false; // Don't actually log, just capture
            }
        };

        $this->handler->setListener($this);
    }

    public function listen(callable $callback): mixed
    {
        $this->enabled = true;
        
        // Store original handlers and add our custom handler
        $this->storeOriginalHandlers();
        $this->addCustomHandler();

        try {
            return $callback();
        } finally {
            $this->enabled = false;
            $this->restoreOriginalHandlers();
        }
    }

    public function addLog(string $level, string $message, array $context, Carbon $timestamp, string $channel): void
    {
        $this->logs[] = new Entry($level, $message, $context, $timestamp, $channel, $this->action);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function getLogCount(): int
    {
        return count($this->logs);
    }

    public function getLogsByLevel(string $level): array
    {
        return array_filter($this->logs, fn($log) => $log->level === $level);
    }

    public function clear(): void
    {
        $this->logs = [];
    }

    public function getHandler(): AbstractHandler
    {
        return $this->handler;
    }

    protected function storeOriginalHandlers(): void
    {
        $logger = Log::getLogger();
        if ($logger instanceof Logger) {
            $this->originalHandlers = $logger->getHandlers();
        }
    }

    protected function addCustomHandler(): void
    {
        $logger = Log::getLogger();
        if ($logger instanceof Logger) {
            $logger->pushHandler($this->handler);
        }
    }

    protected function restoreOriginalHandlers(): void
    {
        $logger = Log::getLogger();
        if ($logger instanceof Logger) {
            // Remove our custom handler
            $handlers = $logger->getHandlers();
            $filteredHandlers = array_filter($handlers, fn($handler) => $handler !== $this->handler);
            
            // Clear all handlers and restore originals
            $logger->setHandlers($this->originalHandlers);
        }
    }
}
