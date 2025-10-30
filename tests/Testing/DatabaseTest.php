<?php

use Iak\Action\Testing\Testable;
use Iak\Action\Testing\Results\Query;
use Illuminate\Support\Facades\DB;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\DatabaseAction;

it('can record database calls for the calling action', function () {
    $result = DatabaseAction::test()
        ->queries(function (array $dbCalls) {
            expect($dbCalls)->toBeGreaterThanOrEqual(2);
            
            // Find the SELECT queries (ignoring CREATE TABLE and INSERT)
            $selectQueries = array_filter($dbCalls, fn($call) => str_contains($call->query, 'SELECT'));
            $selectQueries = array_values($selectQueries);
            
            expect($selectQueries)->toHaveCount(2);
            expect($selectQueries[0])->toBeInstanceOf(Query::class);
            expect($selectQueries[0]->query)->toContain('SELECT * FROM users');
            expect($selectQueries[0]->bindings)->toBe([1]);
            expect($selectQueries[1]->query)->toContain('SELECT * FROM posts');
            expect($selectQueries[1]->bindings)->toBe([1]);
        })
        ->handle();

    expect($result)->toBe('Database queries executed');
});

it('can record database calls for a single action', function () {
    $result = ClosureAction::test()
        ->queries(ClosureAction::class, function (array $dbCalls) {
            expect($dbCalls)->toHaveCount(1);
        })
        ->handle(function () {
            DatabaseAction::make()->handle();
            ClosureAction::make()->handle(function () {
                DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
            });
            return 'done';
        });

    expect($result)->toBe('done');
});

it('can record database calls for a specific action', function ($actions) {
    $result = ClosureAction::test()
        ->queries($actions, function (array $dbCalls) {
            expect($dbCalls)->toBeGreaterThanOrEqual(2);
            
            // Find the SELECT queries
            $selectQueries = array_filter($dbCalls, fn($call) => str_contains($call->query, 'SELECT'));
            expect($selectQueries)->toHaveCount(2);
            
            $firstSelect = array_values($selectQueries)[0];
            expect($firstSelect)->toBeInstanceOf(Query::class);
            expect($firstSelect->query)->toContain('SELECT * FROM users');
        })
        ->handle(function () {
            DatabaseAction::make()->handle();
            return 'done';
        });

    expect($result)->toBe('done');
})->with([
    'asString' => [DatabaseAction::class], 
    'asArray' => [[DatabaseAction::class]]
]);

it('can convert database call to string', function () {
    DatabaseAction::test()
        ->queries(function (array $dbCalls) {
            $dbCall = $dbCalls[0];
            $string = (string) $dbCall;
            expect($string)->toContain('Query:');
            expect($string)->toContain('Bindings:');
            expect($string)->toContain('Time:');
        })
        ->handle();
});

it('throws exception when recordDbCalls method receives invalid callback', function () {
    expect(fn () => ClosureAction::test()->queries(DatabaseAction::class))
        ->toThrow(InvalidArgumentException::class, 'A callback is required');
});

it('throws exception when recordDbCalls method receives invalid class', function () {
    expect(fn () => ClosureAction::test()->queries('NonExistentClass', function () {}))
        ->toThrow(Exception::class);
});
