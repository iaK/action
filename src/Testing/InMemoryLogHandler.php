<?php

namespace Iak\Action\Testing;

use Monolog\LogRecord;
use Illuminate\Support\Carbon;
use Iak\Action\Testing\LogListener;
use Monolog\Handler\AbstractHandler;

class InMemoryLogHandler extends AbstractHandler
{
    protected ?LogListener $listener = null;

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
}
