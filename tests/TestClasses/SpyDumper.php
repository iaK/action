<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Support\Dumper;
use RuntimeException;

class SpyDumper extends Dumper
{
    /** @var array<int, string> */
    public array $dumped = [];

    public function dump(string $report): void
    {
        $this->dumped[] = $report;
    }

    public function dd(string $report): never
    {
        $this->dump($report);

        throw new RuntimeException('SpyDumper: dd() would have terminated.');
    }
}
