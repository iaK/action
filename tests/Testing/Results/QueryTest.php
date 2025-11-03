<?php

use Carbon\CarbonInterval;
use Iak\Action\Testing\Results\Query;

describe('Query', function () {
    it('can create query', function () {
        $query = new Query('SELECT * FROM users', ['id' => 1], 0.5, 'mysql');

        expect($query->query)->toBe('SELECT * FROM users');
        expect($query->bindings)->toBe(['id' => 1]);
        expect($query->time)->toBe(0.5);
        expect($query->connection)->toBe('mysql');
    });

    it('uses default connection', function () {
        $query = new Query('SELECT * FROM users', [], 0.5);

        expect($query->connection)->toBe('default');
    });

    it('can calculate duration', function () {
        $query = new Query('SELECT * FROM users', [], 1.5);

        $duration = $query->duration();

        expect($duration)->toBeInstanceOf(CarbonInterval::class);
        expect($duration->totalMilliseconds)->toBe(1500.0);
    });

    it('has string representation', function () {
        $query = new Query('SELECT * FROM users WHERE id = ?', [1], 0.5, 'mysql');

        $expected = 'Query: SELECT * FROM users WHERE id = ? | Bindings: [1] | Time: 500ms';
        expect((string) $query)->toBe($expected);
    });

    it('calculates duration with zero time', function () {
        $query = new Query('SELECT 1', [], 0.0);

        $duration = $query->duration();

        expect($duration->totalMilliseconds)->toBe(0.0);
    });
});
