<?php

namespace Iak\Action\Execution;

/**
 * The shared traceTo() implementation behind the Middleware interface:
 * middleware hold the recorder for the upcoming invocation (null when
 * tracing is off) and record decisions with `$this->recorder?->record(...)`.
 */
trait TracksTrace
{
    protected ?TraceRecorder $recorder = null;

    public function traceTo(TraceRecorder $recorder): void
    {
        $this->recorder = $recorder;
    }
}
