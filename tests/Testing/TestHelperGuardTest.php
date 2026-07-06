<?php

use Iak\Action\Action;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Mockery\MockInterface;

describe('test helper guard', function () {
    beforeEach(function () {
        // The escape hatch is a process-wide static flag; reset it so the
        // suite stays order-independent under a random seed.
        Action::allowTestHelpers(false);
    });

    afterEach(function () {
        Action::allowTestHelpers(false);
    });

    describe('outside test/local environments', function () {
        beforeEach(function () {
            app()['env'] = 'production';
        });

        it('throws a clear exception for each mock-binding helper', function (string $needle, Closure $call) {
            $thrown = null;

            try {
                $call();
            } catch (RuntimeException $e) {
                $thrown = $e;
            }

            expect($thrown)->toBeInstanceOf(RuntimeException::class);
            expect($thrown->getMessage())
                ->toContain($needle)               // names the blocked helper
                ->toContain('production')          // names the current environment
                ->toContain('allowTestHelpers');   // names the escape hatch
        })->with([
            'fake' => ['fake', fn () => ClosureAction::fake()],
            'only' => ['only', fn () => ClosureAction::test()->only(OtherClosureAction::class)],
            'without' => ['without', fn () => ClosureAction::test()->without(OtherClosureAction::class)],
            'except' => ['except', fn () => ClosureAction::test()->except(OtherClosureAction::class)],
        ]);

        it('allows the mock-binding helpers when opted in via allowTestHelpers()', function () {
            Action::allowTestHelpers();

            $mock = ClosureAction::fake();

            expect($mock)->toBeInstanceOf(MockInterface::class);

            // No throw for the Testable helpers either.
            ClosureAction::test()->without(OtherClosureAction::class);
            ClosureAction::test()->only(OtherClosureAction::class);
            ClosureAction::test()->except(OtherClosureAction::class);
        });

        it('re-guards after the escape hatch is reset', function () {
            Action::allowTestHelpers();

            // Allowed while opted in.
            expect(ClosureAction::fake())->toBeInstanceOf(MockInterface::class);

            Action::allowTestHelpers(false);

            $thrown = null;

            try {
                ClosureAction::fake();
            } catch (RuntimeException $e) {
                $thrown = $e;
            }

            expect($thrown)->toBeInstanceOf(RuntimeException::class);
        });

        it('never guards the observability helpers', function () {
            // test()/profile()/queries()/logs() are Mockery-free and restore
            // container state, so they must work even in production.
            $result = ClosureAction::test()
                ->profile(function ($profiles) {
                    expect($profiles)->toHaveCount(1);
                })
                ->queries(function ($queries) {
                    expect($queries)->toBeIterable();
                })
                ->logs(function ($logs) {
                    expect($logs)->toBeIterable();
                })
                ->handle();

            expect($result)->toBeNull();
        });
    });

    it('allows the mock-binding helpers by default in the testing environment', function () {
        expect(app()->environment())->toBe('testing');

        expect(ClosureAction::fake())->toBeInstanceOf(MockInterface::class);

        ClosureAction::test()->without(OtherClosureAction::class);
        ClosureAction::test()->only(OtherClosureAction::class);
        ClosureAction::test()->except(OtherClosureAction::class);
    });

    it('allows the mock-binding helpers by default in the local environment', function () {
        app()['env'] = 'local';

        expect(ClosureAction::fake())->toBeInstanceOf(MockInterface::class);

        ClosureAction::test()->without(OtherClosureAction::class);
    });
});
