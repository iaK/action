<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

describe('Query Assertions', function () {
    it('passes when no query repeats', function () {
        $result = ClosureAction::test()
            ->assertNoDuplicateQueries()
            ->handle(function () {
                DB::statement('SELECT 1');
                DB::statement('SELECT 2');

                return 'ok';
            });

        expect($result)->toBe('ok');
    });

    it('fails when the same query runs twice, naming the sql', function () {
        expect(fn () => ClosureAction::test()
            ->assertNoDuplicateQueries()
            ->handle(function () {
                DB::statement('SELECT 1');
                DB::statement('SELECT 1');
            }))->toThrow(AssertionFailedError::class, 'SELECT 1');
    });

    it('groups placeholder lists of different lengths as duplicates', function () {
        expect(fn () => ClosureAction::test()
            ->assertNoDuplicateQueries()
            ->handle(function () {
                DB::select('select 1 where 1 in (?, ?)', [1, 2]);
                DB::select('select 1 where 1 in (?, ?, ?)', [1, 2, 3]);
            }))->toThrow(AssertionFailedError::class, 'in (?)');
    });

    it('passes when the query count matches exactly', function () {
        ClosureAction::test()
            ->assertQueryCount(2)
            ->handle(function () {
                DB::statement('SELECT 1');
                DB::statement('SELECT 2');
            });

        expect(true)->toBeTrue();
    });

    it('fails when the query count differs, listing the recorded sql', function () {
        expect(fn () => ClosureAction::test()
            ->assertQueryCount(3)
            ->handle(fn () => DB::statement('SELECT 1')))
            ->toThrow(AssertionFailedError::class, 'Expected exactly 3 queries, 1 recorded');
    });

    it('coexists with a queries() inspection callback', function () {
        $queries = null;

        ClosureAction::test()
            ->queries(function (Collection $recorded) use (&$queries) {
                $queries = $recorded;
            })
            ->assertQueryCount(1)
            ->handle(fn () => DB::statement('SELECT 1'));

        expect($queries)->toHaveCount(1);
    });

    it('skips assertions when the idempotency cache serves the result', function () {
        ClosureAction::test()
            ->idempotent('query-assert-hit')
            ->handle(fn () => DB::statement('SELECT 1'));

        $testable = ClosureAction::test()
            ->idempotent('query-assert-hit')
            ->assertQueryCount(99); // would fail if evaluated

        $testable->handle(fn () => DB::statement('SELECT 1'));

        expect($testable->wasExecuted())->toBeFalse();
    });
});
