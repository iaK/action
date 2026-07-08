<?php

namespace Iak\Action\Testing;

use Illuminate\Support\Carbon;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;

class InMemoryLogHandler extends AbstractHandler
{
    protected ?LogListener $listener = null;

    public function setListener(LogListener $listener): void
    {
        $this->listener = $listener;
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->listener || ! $this->listener->isEnabled()) {
            return false;
        }

        $this->listener->addLog(
            $record->level->getName(),
            $record->message,
            $record->context,
            Carbon::createFromTimestamp($record->datetime->getTimestamp()),
            $record->channel
        );

        // False = not handled, so the record still bubbles to the real
        // handlers: the instrument captures, it never swallows user logs.
        return false;
    }
}
