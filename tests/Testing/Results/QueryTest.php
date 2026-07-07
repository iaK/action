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
        expect($duration->totalMilliseconds)->toEqual(1500); // int on Carbon 2, float on Carbon 3
    });

    it('has string representation', function () {
        $query = new Query('SELECT * FROM users WHERE id = ?', [1], 0.5, 'mysql');

        $expected = 'Query: SELECT * FROM users WHERE id = ? | Bindings: [1] | Time: 500ms';
        expect((string) $query)->toBe($expected);
    });

    it('calculates duration with zero time', function () {
        $query = new Query('SELECT 1', [], 0.0);

        $duration = $query->duration();

        expect($duration->totalMilliseconds)->toEqual(0); // int on Carbon 2, float on Carbon 3
    });

    it('normalizes whitespace runs in the sql', function () {
        $query = new Query("select *\n   from users\t where id = ?", [1], 0.001);

        expect($query->normalizedSql())->toBe('select * from users where id = ?');
    });

    it('collapses placeholder lists so differing counts group together', function () {
        $two = new Query('select * from users where id in (?, ?)', [1, 2], 0.001);
        $three = new Query('select * from users where id in (?,?,?)', [1, 2, 3], 0.001);

        expect($two->normalizedSql())->toBe('select * from users where id in (?)')
            ->and($three->normalizedSql())->toBe($two->normalizedSql());
    });
});
