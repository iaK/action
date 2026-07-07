<?php

use Iak\Action\Tests\TestClasses\ClosureAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Cache::flush();
    Schema::dropIfExists('things');
    Schema::create('things', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
});

afterEach(function () {
    Schema::dropIfExists('things');
});

describe('dryRun()', function () {
    it('rolls back database work and still returns the result', function () {
        $result = ClosureAction::test()->dryRun()->handle(function () {
            DB::table('things')->insert(['name' => 'ghost']);

            return DB::table('things')->count();
        });

        // Inside the run the row existed; after it, nothing remains.
        expect($result)->toBe(1);
        expect(DB::table('things')->count())->toBe(0);
    });

    it('still reports the recorded queries', function () {
        $seen = null;

        ClosureAction::test()
            ->queries(function (Collection $queries) use (&$seen) {
                $seen = $queries;
            })
            ->dryRun()
            ->handle(function () {
                DB::table('things')->insert(['name' => 'ghost']);

                return 'done';
            });

        expect($seen)->not->toBeNull();
        expect($seen->first()->query)->toContain('insert into "things"');
    });

    it('does not consume an idempotency key', function () {
        $count = 0;
        $closure = function () use (&$count) {
            $count++;

            return 'value';
        };

        $rehearsal = ClosureAction::test()->idempotent('dry-key')->dryRun();

        expect($rehearsal->handle($closure))->toBe('value');
        expect($rehearsal->wasExecuted())->toBeTrue();
        expect($count)->toBe(1);

        // The rehearsal was rolled back, so the real run still executes.
        $real = ClosureAction::test()->idempotent('dry-key');

        expect($real->handle($closure))->toBe('value');
        expect($real->wasExecuted())->toBeTrue();
        expect($count)->toBe(2);
    });

    it('rolls back even when the action throws', function () {
        $throwing = function () {
            DB::table('things')->insert(['name' => 'ghost']);

            throw new RuntimeException('boom');
        };

        expect(fn () => ClosureAction::test()->dryRun()->handle($throwing))
            ->toThrow(RuntimeException::class, 'boom');

        expect(DB::table('things')->count())->toBe(0);
    });

    it('returns the testable for chaining', function () {
        $testable = ClosureAction::test();

        expect($testable->dryRun())->toBe($testable);
    });
});
