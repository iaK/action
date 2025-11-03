<?php

use Iak\Action\Testing\QueryListener;
use Iak\Action\Testing\Results\Query;
use Illuminate\Support\Facades\DB;

describe('QueryListener', function () {
    beforeEach(function () {
        // Clear any existing listeners
        DB::getEventDispatcher()->flush('Illuminate\Database\Events\QueryExecuted');
    });

    it('can create query listener', function () {
        $listener = new QueryListener;

        expect($listener)->toBeInstanceOf(QueryListener::class);

        // Use reflection to access the protected property
        $reflection = new ReflectionClass($listener);
        $property = $reflection->getProperty('enabled');
        $property->setAccessible(true);
        expect($property->getValue($listener))->toBeFalse();
    });

    it('can listen for queries', function () {
        $listener = new QueryListener;

        $result = $listener->listen(function () {
            DB::select('SELECT 1');

            return 'test result';
        });

        expect($result)->toBe('test result');

        // Use reflection to access the protected property
        $reflection = new ReflectionClass($listener);
        $property = $reflection->getProperty('enabled');
        $property->setAccessible(true);
        expect($property->getValue($listener))->toBeFalse();
    });

    it('captures queries during listening', function () {
        $listener = new QueryListener;

        $listener->listen(function () {
            DB::select('SELECT 1 as test');
            DB::select('SELECT 2 as test');
        });

        $queries = $listener->getQueries();

        expect($queries)->toHaveCount(2);
        expect($queries[0])->toBeInstanceOf(Query::class);
        expect($queries[0]->query)->toContain('SELECT 1 as test');
        expect($queries[0]->bindings)->toBe([]);
    });

    it('can get call count', function () {
        $listener = new QueryListener;

        $listener->listen(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 2');
            DB::select('SELECT 3');
        });

        expect($listener->getCallCount())->toBe(3);
    });

    it('can get total time', function () {
        $listener = new QueryListener;

        $listener->listen(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 2');
        });

        $totalTime = $listener->getTotalTime();

        expect($totalTime)->toBeFloat();
        expect($totalTime)->toBeGreaterThanOrEqual(0);
    });

    it('can clear queries', function () {
        $listener = new QueryListener;

        $listener->listen(function () {
            DB::select('SELECT 1');
        });

        expect($listener->getCallCount())->toBe(1);

        $listener->clear();

        expect($listener->getCallCount())->toBe(0);
    });

    it('maintains query state across multiple listen calls', function () {
        $listener = new QueryListener;

        // First listen call
        $listener->listen(function () {
            DB::select('SELECT 1');
        });

        expect($listener->getCallCount())->toBe(1);

        // Second listen call - should accumulate queries
        $listener->listen(function () {
            DB::select('SELECT 2');
        });

        expect($listener->getCallCount())->toBe(2);
    });

    it('can get queries with proper query details', function () {
        $listener = new QueryListener;

        $listener->listen(function () {
            DB::select('SELECT ? as test', [123]);
        });

        $queries = $listener->getQueries();

        expect($queries)->toHaveCount(1);
        expect($queries[0]->query)->toContain('SELECT ? as test');
        expect($queries[0]->bindings)->toBe([123]);
        expect($queries[0]->connection)->toBe('testing'); // In test environment, default connection is 'testing'
        expect($queries[0]->time)->toBeFloat();
    });

    it('handles enabled state correctly', function () {
        $listener = new QueryListener;

        // Initially disabled
        $reflection = new ReflectionClass($listener);
        $property = $reflection->getProperty('enabled');
        $property->setAccessible(true);
        expect($property->getValue($listener))->toBeFalse();

        // During listen call - should be enabled
        $listener->listen(function () use ($listener, $property) {
            expect($property->getValue($listener))->toBeTrue();
            DB::select('SELECT 1');
        });

        // After listen call - should be disabled again
        expect($property->getValue($listener))->toBeFalse();
    });

    it('captures queries with different connection names', function () {
        $listener = new QueryListener;

        $listener->listen(function () {
            DB::connection('testing')->select('SELECT 1');
        });

        $queries = $listener->getQueries();

        expect($queries)->toHaveCount(1);
        expect($queries[0]->connection)->toBe('testing');
    });
});
