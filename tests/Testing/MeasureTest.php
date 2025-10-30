<?php

use Iak\Action\Testing\Testable;
use Iak\Action\Testing\Results\Measurement;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\FireEventAction;
use Iak\Action\Tests\TestClasses\SayHelloAction;

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
