<?php

use Iak\Action\Testing\Testable;
use Mockery\MockInterface;
use Iak\Action\Testing\Measurement;
use Iak\Action\Testing\DatabaseCall;
use Illuminate\Support\Facades\DB;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\DatabaseAction;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\FireEventAction;

it('can create testable action with callback', function () {
    $callbackExecuted = false;
    $testable = FireEventAction::test(function ($testable) use (&$callbackExecuted) {
        $callbackExecuted = true;
        expect($testable)->toBeInstanceOf(Testable::class);
    });
    
    expect($callbackExecuted)->toBeTrue();
    expect($testable)->toBeInstanceOf(Testable::class);
});

it('can mock actions inside other actions', function () {
    ClosureAction::test()
        ->only(FireEventAction::class)
        ->handle(function () {
            FireEventAction::make()->handle();
            SayHelloAction::make()->handle();
        });

    expect(SayHelloAction::make())
        ->tobeinstanceof(MockInterface::class);
});

it('can use without method with string class', function () {
    FireEventAction::test()
        ->without(SayHelloAction::class)
        ->handle();
    
    expect(SayHelloAction::make())->toBeInstanceOf(MockInterface::class);
});

it('can use without method with array of classes', function () {
    ClosureAction::test()
        ->without([SayHelloAction::class, ClosureAction::class])
        ->handle(function () {
            SayHelloAction::make()->handle();
            ClosureAction::make()->handle();
        });
    
    // Both classes should be mocked when resolved
    expect(SayHelloAction::make())->toBeInstanceOf(MockInterface::class);
    expect(ClosureAction::make())->toBeInstanceOf(MockInterface::class);
});


it('can use without method and specify return value', function () {
    $result = ClosureAction::test()
        ->without([SayHelloAction::class => 'Mocked hello, World!'])
        ->handle(function () {
            return SayHelloAction::make()->handle();
        });
    
    expect($result)->toBe('Mocked hello, World!');
});

it('can use without method and specify return value for several actions', function () {
    $result = ClosureAction::test()
        ->without([
            // Not nested
            SayHelloAction::class => 'Mocked hello, World!',
            // Nested
            [FireEventAction::class => 'Mocked event!'],
        ])
        ->handle(function () {
            return SayHelloAction::make()->handle() . ' ' . FireEventAction::make()->handle();
        });
    
    expect($result)->toBe('Mocked hello, World! Mocked event!');
});

it('can use only method with array parameter', function () {
    ClosureAction::test()
        ->only([FireEventAction::class, SayHelloAction::class])
        ->handle(function () {
            SayHelloAction::make()->handle();
            FireEventAction::make()->handle();
            ClosureAction::make()->handle();
        });
    
    expect(FireEventAction::make())->toBeInstanceOf(FireEventAction::class);
    expect(SayHelloAction::make())->toBeInstanceOf(SayHelloAction::class);
    expect(ClosureAction::make())->toBeInstanceOf(MockInterface::class);
});

it('throws exception when without method receives invalid parameter', function () {
    expect(fn () => FireEventAction::test()->without(123))
        ->toThrow(Exception::class);
});

it('can measure the duration of an action', function () {
    $result = ClosureAction::test()
        ->measure(function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0]->class)->toBe(ClosureAction::class);
            expect($measurements[0]->start)->toBeLessThan($measurements[0]->end);
            expect($measurements[0]->duration()->totalMilliseconds)->toBeGreaterThan(0);
            expect($measurements[0])->toBeInstanceOf(Measurement::class);
        })
        ->handle(function () {
            usleep(1000);

            return 'done';
        });

    expect($result)->toBe('done');
});

it('can measure the duration of an action with a specific action', function ($actions) {
    $result = ClosureAction::test()
        ->measure($actions, function (array $measurements) {
            expect($measurements)->toHaveCount(1);
            expect($measurements[0])->toBeInstanceOf(Measurement::class);
            expect($measurements[0]->class)->toBe(FireEventAction::class);
        })
        ->handle(function () {
            FireEventAction::make()->handle();

            return 'done';
        });

    expect($result)->toBe('done');
})->with([
    'asString' => [FireEventAction::class], 
    'asArray' => [[FireEventAction::class]]
]);

it('can measure several actions', function () {
    ClosureAction::test()
        ->measure([FireEventAction::class, SayHelloAction::class], function (array $measurements) {
            expect($measurements)->toHaveCount(2);
            expect($measurements[0]->class)->toBe(SayHelloAction::class); // Executed first
            expect($measurements[1]->class)->toBe(FireEventAction::class);   // Executed second
        })
        ->handle(function () {
            SayHelloAction::make()->handle();
            FireEventAction::make()->handle();
        });
});

it('can convert measurement to string', function () {
    FireEventAction::test()
        ->measure(function (array $measurements) {
            $measurement = $measurements[0];
            expect((string) $measurement)->toBe("{$measurement->class} took {$measurement->duration()->totalMilliseconds}ms");
        })
        ->handle();
});

it('throws exception when measure method receives invalid callback', function () {
    expect(fn () => ClosureAction::test()->measure(FireEventAction::class))
        ->toThrow(InvalidArgumentException::class, 'A callback is required');
});

it('throws exception when measure method receives invalid class', function () {
    expect(fn () => ClosureAction::test()->measure('NonExistentClass', function () {}))
        ->toThrow(Exception::class);
});

it('can record database calls for the calling action', function () {
    $result = DatabaseAction::test()
        ->queries(function (array $dbCalls) {
            expect($dbCalls)->toBeGreaterThanOrEqual(2);
            
            // Find the SELECT queries (ignoring CREATE TABLE and INSERT)
            $selectQueries = array_filter($dbCalls, fn($call) => str_contains($call->query, 'SELECT'));
            $selectQueries = array_values($selectQueries);
            
            expect($selectQueries)->toHaveCount(2);
            expect($selectQueries[0])->toBeInstanceOf(DatabaseCall::class);
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
            expect($firstSelect)->toBeInstanceOf(DatabaseCall::class);
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

