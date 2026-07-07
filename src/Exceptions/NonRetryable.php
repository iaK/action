<?php

namespace Iak\Action\Exceptions;

/**
 * Marks an exception that retry() must not retry by default: throwing it
 * means another in-process attempt is pointless (an open circuit breaker, a
 * domain rule that will not change between attempts, ...). Implement it on
 * your own exceptions to opt them out of retrying; an explicit when filter
 * passed to retry() overrules the marker entirely.
 */
interface NonRetryable {}
