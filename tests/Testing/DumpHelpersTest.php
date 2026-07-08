<?php

use Iak\Action\Support\Dumper;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\SpyDumper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();
    $this->dumper = new SpyDumper;
    app()->instance(Dumper::class, $this->dumper);
});

describe('dump helpers', function () {
    it('dumps recorded queries without an explicit queries() registration', function () {
        ClosureAction::test()
            ->dumpQueries()
            ->handle(function () {
                DB::statement('SELECT 1');
                DB::statement('SELECT 2');
            });

        expect($this->dumper->dumped)->toHaveCount(1);
        expect($this->dumper->dumped[0])
            ->toContain('[queries] 2 recorded')
            ->toContain('1. SELECT 1')
            ->toContain('2. SELECT 2');
    });

    it('ddQueries() dumps and terminates', function () {
        expect(fn () => ClosureAction::test()
            ->ddQueries()
            ->handle(fn () => DB::statement('SELECT 1')))
            ->toThrow(RuntimeException::class, 'dd() would have terminated');

        expect($this->dumper->dumped)->toHaveCount(1);
        expect($this->dumper->dumped[0])->toContain('[queries] 1 recorded');
    });

    it('dumps recorded logs with their context', function () {
        ClosureAction::test()
            ->dumpLogs()
            ->handle(function (): void {
                Log::info('hello', ['id' => 7]);
            });

        expect($this->dumper->dumped)->toHaveCount(1);
        expect($this->dumper->dumped[0])
            ->toContain('[logs] 1 recorded')
            ->toContain('[info] hello')
            ->toContain('{"id":7}');
    });

    it('dumps emitted events with their payload', function () {
        ClosureAction::test()
            ->dumpEvents()
            ->handle(function (ClosureAction $action): void {
                $action->event('test.event.a', ['id' => 7]);
            });

        expect($this->dumper->dumped)->toHaveCount(1);
        expect($this->dumper->dumped[0])
            ->toContain('[events] 1 emitted')
            ->toContain('test.event.a')
            ->toContain('{"id":7}');
    });

    it('dumps the profile', function () {
        ClosureAction::test()->dumpProfile()->handle(fn (): string => 'x');

        expect($this->dumper->dumped)->toHaveCount(1);
        expect($this->dumper->dumped[0])
            ->toContain('[profile] 1 recorded')
            ->toContain(ClosureAction::class)
            ->toContain('ms');
    });

    it('dumps several instruments in one run', function () {
        ClosureAction::test()
            ->dumpQueries()
            ->dumpProfile()
            ->handle(fn () => DB::statement('SELECT 1'));

        expect($this->dumper->dumped)->toHaveCount(2);
    });

    it('skips the dump when the idempotency cache serves the result', function () {
        ClosureAction::test()->idempotent('dump-skip')->handle(fn (): string => 'v');

        ClosureAction::test()
            ->dumpQueries()
            ->idempotent('dump-skip')
            ->handle(fn (): string => 'v');

        expect($this->dumper->dumped)->toBe([]);
    });
});
