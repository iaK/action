<?php

use Illuminate\Support\Facades\DB;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;

describe('Database Feature', function () {
    it('can record database calls for the calling action', function () {
        $result = ClosureAction::test()
        ->queries(function (array $dbCalls) {
            expect($dbCalls)->toHaveCount(2);
            
            expect($dbCalls[0]->query)->toBe('SELECT 1');
            expect($dbCalls[1]->query)->toBe('SELECT 2');
        })
        ->handle(function () {
            DB::statement('SELECT 1');
            DB::statement('SELECT 2');
            return 'done';
        });

        expect($result)->toBe('done');
    });

    it('can record database calls for a single action', function () {
        $result = ClosureAction::test()
        ->queries(ClosureAction::class, function (array $dbCalls) {
            expect($dbCalls)->toHaveCount(1);
            expect($dbCalls[0]->query)->toBe('SELECT 1');
        })
        ->handle(function () {
            OtherClosureAction::make()->handle(function () {
                DB::statement('SELECT 2');
            });
            ClosureAction::make()->handle(function () {
                DB::statement('SELECT 1');
            });
            return 'done';
        });

        expect($result)->toBe('done');
        });

    it('can record database calls for a specific action', function ($actions) {
        $result = ClosureAction::test()
        ->queries($actions, function ($queries) {
            expect($queries)->toHaveCount(1);
            expect($queries[0]->query)->toBe('SELECT 1');
        })
        ->handle(function () {
            ClosureAction::make()->handle(function () {
                DB::statement('SELECT 1');
            });
            return 'done';
        });

        expect($result)->toBe('done');
    })->with([
        'asString' => [ClosureAction::class], 
        'asArray' => [[ClosureAction::class]]
    ]);

    it('can convert database call to string', function () {
        ClosureAction::test()
        ->queries(function ($queries) {
            expect((string) $queries[0])->toMatch('/Query: SELECT 1 | Bindings: \[\] | Time: \d+\.\d+ms/');
        })
        ->handle(function () {
            DB::statement('SELECT 1');
        });
    });

    it('throws exception when recordDbCalls method receives invalid callback', function () {
        expect(fn () => ClosureAction::test()->queries(ClosureAction::class))
            ->toThrow(InvalidArgumentException::class, 'A callback is required');
    });

    it('throws exception when recordDbCalls method receives invalid class', function () {
        expect(fn () => ClosureAction::test()->queries('NonExistentClass', function () {}))
            ->toThrow(Exception::class);
    });
});
