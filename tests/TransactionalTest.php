<?php

use Iak\Action\PendingAction;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('things');
    Schema::create('things', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
});

afterEach(function () {
    Schema::dropIfExists('things');
});

describe('transactional()', function () {
    it('commits the work of a successful run', function () {
        ClosureAction::make()->transactional()->handle(function () {
            DB::table('things')->insert(['name' => 'kept']);

            return 'done';
        });

        expect(DB::table('things')->count())->toBe(1);
    });

    it('rolls the work back when the action throws', function () {
        $throwing = function () {
            DB::table('things')->insert(['name' => 'discarded']);

            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::make()->transactional()->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        expect(DB::table('things')->count())->toBe(0);
    });

    it('retries the transaction on a concurrency error up to the given attempts', function () {
        $runs = 0;

        $flaky = function () use (&$runs) {
            $runs++;

            DB::table('things')->insert(['name' => 'attempt-'.$runs]);

            if ($runs === 1) {
                // Laravel's transaction() recognises this as a concurrency
                // error and re-runs the transaction closure.
                throw new Exception('deadlock detected');
            }

            return 'done';
        };

        $result = ClosureAction::make()->transactional(attempts: 2)->handle($flaky);

        expect($result)->toBe('done');
        expect($runs)->toBe(2);

        // The first attempt's insert was rolled back.
        expect(DB::table('things')->count())->toBe(1);
    });

    it('gives every retry() attempt its own fresh transaction', function () {
        $runs = 0;

        $flaky = function () use (&$runs) {
            $runs++;

            DB::table('things')->insert(['name' => 'attempt-'.$runs]);

            if ($runs === 1) {
                throw new RuntimeException('flaky');
            }

            return 'done';
        };

        $result = ClosureAction::make()->retry(times: 2)->transactional()->handle($flaky);

        expect($result)->toBe('done');
        expect($runs)->toBe(2);

        // The failed attempt rolled back; only the success committed.
        expect(DB::table('things')->count())->toBe(1);
    });

    it('rejects attempts below one', function () {
        expect(fn () => ClosureAction::make()->transactional(attempts: 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('returns the chainable wrapper', function () {
        expect(ClosureAction::make()->transactional())->toBeInstanceOf(PendingAction::class);
    });
});
