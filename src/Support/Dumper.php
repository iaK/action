<?php

namespace Iak\Action\Support;

/**
 * Prints the preformatted reports behind the dump helpers (dumpQueries(),
 * dumpTrace(), ...). Deliberately NOT symfony/var-dumper: the reports are
 * plain multi-line text that var-dumper would render as escaped string
 * blocks, and the package forbids dump()/dd() function calls (see ArchTest).
 * Resolved through the container so tests swap in a spy instead of capturing
 * process output.
 *
 * @internal
 */
class Dumper
{
    public function dump(string $report): void
    {
        echo $report.PHP_EOL;
    }

    /**
     * Print and stop the process — the dd() contract, mirroring
     * DB::ddRawSql(). Kept one line thin so everything else stays testable.
     */
    public function dd(string $report): never
    {
        $this->dump($report);

        exit(1);
    }
}
