<?php

use Iak\Action\Testable;
use Mockery\MockInterface;
use Iak\Action\Measurement;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\SayHelloAction;
use Iak\Action\Tests\TestClasses\FireEventAction;
use Iak\Action\Tests\TestClasses\MiddleManAction;

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
