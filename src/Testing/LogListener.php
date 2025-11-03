<?php

namespace Iak\Action\Testing;

use Carbon\Carbon;
use Iak\Action\Testing\Results\Entry;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;

class LogListener implements Listener
{
    protected bool $enabled = false;

    /** @var Entry[] */
    protected array $logs = [];

    protected InMemoryLogHandler $handler;

    /** @var list<HandlerInterface> */
    protected array $originalHandlers = [];

    protected ?string $action;

    public function __construct(?string $action = null)
    {
        $this->action = $action;
        // Create a custom handler that captures logs
        $this->handler = new InMemoryLogHandler;

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

    /**
     * @param  array<mixed>  $context
     */
    public function addLog(string $level, string $message, array $context, Carbon $timestamp, string $channel): void
    {
        $this->logs[] = new Entry($level, $message, $context, $timestamp, $channel, $this->action);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return Entry[]
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    public function getLogCount(): int
    {
        return count($this->logs);
    }

    /**
     * @return Entry[]
     */
    public function getLogsByLevel(string $level): array
    {
        return array_filter($this->logs, fn ($log) => $log->level === $level);
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

            // Clear all handlers and restore originals
            $logger->setHandlers($this->originalHandlers);
        }
    }
}
