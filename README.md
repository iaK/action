# Laravel Action

[![Latest Version on Packagist](https://img.shields.io/packagist/v/iak/action.svg?style=flat-square)](https://packagist.org/packages/iak/action)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/iaK/action/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/iaK/action/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/iaK/action/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/iaK/action/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/iak/action.svg?style=flat-square)](https://packagist.org/packages/iak/action)

A simple way to organize your business logic in Laravel applications.

## Installation

```bash
composer require iak/action
```

## Production API

### Creating an Action

```php
<?php

namespace App\Actions;

use Iak\Action\Action;

class SayHelloAction extends Action
{
    public function handle()
    {
        return "Hello";
    }
}
```

### Using an Action

```php
<?php

namespace App\Http\Controllers;

use App\Actions\SayHelloAction;

class HomeController extends Controller
{
    public function index(SayHelloAction $action)
    {
        $result = $action->handle();

        // Or create it using the make() method

        $result = SayHelloAction::make()->handle();

        return response()->json($result);
    }
}
```

### Events

Actions can emit and listen to events:

```php
<?php

namespace App\Actions;

use Iak\Action\Action;
use Iak\Action\EmitsEvents;

#[EmitsEvents(['hello_said'])]
class SayHelloAction extends Action
{
    public function handle()
    {
        $result = "Hello";
        
        $this->event('hello_said', $result);

        return $result;
    }
}
```

Listen to events:

```php
$action = SayHelloAction::make()
    ->on('hello_said', function ($result) {
        // Do something when hello is said
        Log::info("Hello said: {$result}");
    })
    ->handle();
```

#### Forwarding Events

When you have nested actions, you can use `forwardEvents()` to propagate events from child actions to parent classes that use the `HandlesEvents` trait, even if there are intermediate classes between them. This is particularly useful when services call actions and want to listen to events from those actions.

```php
<?php

namespace App\Services;

use Iak\Action\HandlesEvents;
use Iak\Action\EmitsEvents;

#[EmitsEvents(['email_sent', 'email_failed'])]
class EmailService
{
    use HandlesEvents;

    public function sendWelcomeEmail($user)
    {
        // Call the action with forwardEvents() to propagate events to this service
        SendEmailAction::make()
            ->forwardEvents(['email_sent'])
            ->handle($user);
    }
}
```

```php
<?php

//...

(new EmailService)
    ->on('email_sent', function($user) {
        Log::info('email sent', ['user_id' => $user->id]);
    })
    ->sendWelcomeEmail($user);
```

**How it works:**
- When `forwardEvents()` is called on an action, it captures the classes using the `HandlesEvents` trait that are on the call stack at that moment; events emitted by the action are then forwarded to the nearest captured ancestor
- Call `forwardEvents()` from within the scope that should receive the events (as in the example above); calling it again captures the new scope
- The parent class (service, action, etc.) must also declare the event in its `#[EmitsEvents(...)]` attribute to receive forwarded events
- Events can propagate through multiple layers of intermediate classes, as long as the ancestor class uses the `HandlesEvents` trait

**Forwarding specific events:**

```php
SendEmailAction::make()
    ->forwardEvents(['email_sent', 'email_failed'])
    ->handle($user);
```

**Forwarding all allowed events:**

If you call `forwardEvents()` without arguments, all events declared in the action's `#[EmitsEvents(...)]` attribute will be forwarded:

```php
SendEmailAction::make()
    ->forwardEvents()  // Forwards all events: ['email_sent', 'email_failed']
    ->handle($user);
```

### Idempotent execution

Run an action at most once per key. The first successful call executes and caches its result; later calls with the same key return the cached result without executing again:

```php
// Executes once. The second call returns the cached result.
ChargeCustomer::make()->idempotent("charge:{$order->id}")->handle($order);
ChargeCustomer::make()->idempotent("charge:{$order->id}")->handle($order);
```

Choose a key that identifies the unit of work (an order id, a webhook id, a request uuid). Keys are scoped per action class, so two different actions can safely share the same key.

By default the entry is remembered forever on the default cache store. Pass a TTL (seconds, a `DateInterval`, or an expiry `DateTimeInterface`) and/or a cache store name to change that:

```php
// Cache for one hour on the "redis" store.
SendReminder::make()->idempotent("reminder:{$user->id}", 3600, 'redis')->handle($user);
```

Only successful runs consume the key: if `handle()` throws, the exception propagates and the key stays free, so the next call executes again. When the cache store supports locks, concurrent callers are serialised so the action runs only once even under a race.

Bust an entry to allow it to run again:

```php
ChargeCustomer::make()->forgetIdempotency("charge:{$order->id}");
```

> On a persistent store (redis, database, file, …) the action's return value is serialized into the cache, so it must be serializable to be replayed. The `array` store keeps values in memory for the current process only.

#### Typed results and autocomplete

The wrapper mirrors your action's own `handle()` signature (via a generic `@mixin`), so PHPStan checks the arguments and infers the return type exactly:

```php
$order = ChargeCustomer::make()->idempotent("charge:{$order->id}")->handle($order);
// PHPStan knows $order is Order, and flags wrong arguments against ChargeCustomer::handle()
```

Some editors don't resolve generic mixins yet for in-editor autocomplete (tracked upstream: [PhpStorm](https://youtrack.jetbrains.com/issue/WI-69638), [Intelephense](https://github.com/bmewburn/vscode-intelephense/issues/3280)). If that's you, `run()` gives the same typing through a closure that receives the action — full autocomplete and an inferred return everywhere, today:

```php
$order = ChargeCustomer::make()
    ->idempotent("charge:{$order->id}")
    ->run(fn (ChargeCustomer $action) => $action->handle($order));
```

Both entry points share the same key and cache entry — pick whichever reads better and switch freely.

#### With the test instruments

Idempotency chains with the [test instruments](#testing--debugging) in any order and shares keys with the production wrapper:

```php
$testable = ChargeCustomer::test()
    ->profile(fn ($profiles) => /* ... */)
    ->idempotent("charge:{$order->id}");

$testable->handle($order);

$testable->wasExecuted(); // true on the run that executed, false when served from cache
```

On a cache hit nothing executes, so nothing is instrumented and no inspection callbacks fire — `wasExecuted()` tells the runs apart.

### Retries, fallbacks and circuit breakers

The resilience helpers wrap `handle()` the same way `idempotent()` does, and they all return the same chainable wrapper:

```php
// Re-run a flaky action: up to 3 total attempts, pausing 100ms then 500ms.
SyncInventory::make()->retry(times: 3, backoff: [100, 500])->handle($warehouse);

// Degrade gracefully when the action (still) fails.
$rate = FetchExchangeRate::make()
    ->retry(times: 2)
    ->fallback(fn (Throwable $e) => ExchangeRate::lastKnown($currency))
    ->handle($currency);

// Stop hammering a dependency that keeps failing.
ChargeCustomer::make()
    ->circuitBreaker('stripe', threshold: 5, cooldown: 60)
    ->handle($order);
```

**`retry(times: 3, backoff: 0, when: null)`** re-runs `handle()` when it throws, up to `times` total attempts. `backoff` is the pause between attempts in milliseconds: a fixed value, a per-attempt schedule (`[100, 500]` — the last entry repeats), or a closure receiving the attempt number and the exception. Sleeping goes through Laravel's `Sleep`, so tests control it with `Sleep::fake()`. By default every exception is retried except those implementing the `Iak\Action\Exceptions\NonRetryable` marker interface — implement it on your own exceptions to fail fast, or pass a `when:` closure to decide entirely yourself.

**`fallback(fn (Throwable $e) => $value)`** answers with a fallback value when the action ultimately throws — including after exhausted retries or on an open circuit breaker. Rethrow from the closure to decline.

**`circuitBreaker(key: null, threshold: 5, cooldown: 60, store: null)`** opens after `threshold` consecutive failures: further calls throw `CircuitOpenException` (with `availableIn()` seconds) without executing, giving the dependency `cooldown` seconds to recover before a single probe is let through. The breaker state is cache-backed and scoped to the action class by default — give the breakers of one shared dependency the same explicit key so they trip together across actions and processes.

#### The nesting order is fixed

Chaining order never changes the semantics. The wrapper always nests the concerns in one documented order:

```
fallback → idempotent → retry → circuit breaker → handle()
```

Which reads as: failed attempts never consume an idempotency key (only the first success is cached), every retry attempt consults and feeds the circuit breaker, an open circuit fails fast instead of being retried, and a fallback value is never cached as a real result.

## Testing & debugging

Actions provide helpful static methods for testing and debugging:

```php
// Create a fake for testing
$action = SayHelloAction::fake();

// Create a testable action to help test logs, performance, database queries and more
$action = SayHelloAction::test();
```

> **Heads up — the mock-binding helpers are test-only.**
> `Action::fake()` and `Testable::only()` / `without()` / `except()` bind Mockery
> mocks into the container, so they throw a `RuntimeException` when called outside
> a test context — they run only when `runningUnitTests()` is true or the app
> environment is `local` or `testing`. This stops a forgotten `fake()` from
> silently replacing real actions with mocks in staging or production. If you
> genuinely need them elsewhere, opt in explicitly with `Action::allowTestHelpers()`.
>
> The observability helpers — `test()->profile()`, `->queries()` and `->logs()` —
> are **not** guarded. They are Mockery-free and restore the container to its
> previous state after running, so they are the supported way to profile or
> inspect an action in production.

### Basic Testing

```php
<?php

use App\Actions\SayHelloAction;

it('says hello', function () {
    $result = SayHelloAction::make()->handle();
    
    expect($result)->toBe('Hello');
});

it('can fake an action', function () {
    $action = SayHelloAction::fake();
    
    expect($action)->toBeInstanceOf(MockInterface::class);
});
```

### Mocking Actions in Tests

When testing actions that call other actions, you can control which actions execute their real logic and which are mocked.

#### The `only()` Method

The `only()` method specifies which actions should **execute normally**. All other actions will be automatically mocked.

```php
use App\Actions\ProcessOrderAction;
use App\Actions\CalculateTaxAction;
use App\Actions\ChargeCustomerAction;
use App\Actions\SendEmailAction;

it('only executes specific actions', function () {
    ProcessOrderAction::test()
        ->only([ChargeCustomerAction::class, CalculateTaxAction::class])
        ->handle(function () {
            // ChargeCustomerAction executes normally
            // CalculateTaxAction executes normally
            // SendEmailAction is automatically mocked
        });
});
```

You can also specify a single action:

```php
it('allows only one action to execute', function () {
    ProcessOrderAction::test()
        ->only(ChargeCustomerAction::class)
        ->handle();
});
```

#### The `without()` Method

The `without()` method mocks specific actions, preventing them from executing their real `handle()` method. All other actions execute normally.

```php
it('mocks specific actions', function () {
    ProcessOrderAction::test()
        ->without(SendEmailAction::class)
        ->handle(function () {
            // ChargeCustomerAction executes normally
            // CalculateTaxAction executes normally
            // SendEmailAction is mocked
        });
});
```

You can mock multiple actions:

```php
it('mocks multiple actions', function () {
    ProcessOrderAction::test()
        ->without([SendEmailAction::class, ChargeCustomerAction::class])
        ->handle();
});
```

You can also specify return values for mocked actions:

```php
it('mocks actions with custom return values', function () {
    $result = ProcessOrderAction::test()
        ->without([
            CalculateTaxAction::class => 10.50,
            SendEmailAction::class => true,
        ])
        ->handle();
});
```

#### The `except()` Method

The `except()` method is an alias for `without()`, providing an alternative syntax that may be more readable in certain contexts.

### Testing Database Queries

The `queries()` method allows you to record and inspect database queries executed during action execution. This can be really helpful when debugging performance issues, n+1 queries and more.

```php
use App\Actions\ProcessOrderAction;
use Illuminate\Support\Facades\DB;

it('executes the correct database queries', function () {
    ProcessOrderAction::test()
        ->queries(function ($queries) {
            expect($queries)->toHaveCount(2);
            expect($queries[0]->query)->toContain('INSERT INTO orders');
            expect($queries[1]->query)->toContain('UPDATE inventory');
            expect($queries[0]->action)->toBe(ProcessOrderAction::class);
        })
        ->handle($orderData);
});
```

To track queries for a specific nested action:

```php
it('tracks queries from nested actions', function () {
    ProcessOrderAction::test()
        ->queries(CalculateTaxAction::class, function ($queries) {
            expect($queries)->toHaveCount(1);
            expect($queries[0]->query)->toContain('SELECT');
        })
        ->handle($orderData);
});
```

### Testing Logs

The `logs()` method allows you to capture and verify log entries written during action execution:

```php
use App\Actions\ProcessOrderAction;
use Illuminate\Support\Facades\Log;

it('logs important events', function () {
    ProcessOrderAction::test()
        ->logs(function ($logs) {
            expect($logs)->toHaveCount(2);
            expect($logs[0]->level)->toBe('INFO');
            expect($logs[0]->message)->toBe('Order processing started');
            expect($logs[1]->level)->toBe('ERROR');
            expect($logs[1]->message)->toBe('Payment failed');
            expect($logs[0]->context)->toBeArray();
        })
        ->handle($orderData);
});
```

To track logs from a specific nested action:

```php
it('tracks logs from nested actions', function () {
    ProcessOrderAction::test()
        ->logs(SendEmailAction::class, function ($logs) {
            expect($logs)->toHaveCount(1);
            expect($logs[0]->message)->toBe('Email sent successfully');
        })
        ->handle($orderData);
});
```

### Profiling Actions

The `profile()` method allows you to measure execution time, memory usage, and track memory records:

```php
use App\Actions\ProcessOrderAction;

it('profiles action performance', function () {
    ProcessOrderAction::test()
        ->profile(function ($profiles) {
            expect($profiles)->toHaveCount(1);
            expect($profiles[0]->class)->toBe(ProcessOrderAction::class);
            expect($profiles[0]->duration()->totalMilliseconds)->toBeLessThan(100);
            expect($profiles[0]->memoryUsed()->bytes())->toBeGreaterThan(0);
        })
        ->handle($orderData);
});
```

You can also track memory points during execution:

```php
it('tracks memory usage at specific points', function () {
    ProcessOrderAction::test()
        ->profile(function ($profiles) {
            $records = $profiles[0]->records();
            expect($records)->toHaveCount(2);
            expect($records[0]->name)->toBe('before-processing');
            expect($records[1]->name)->toBe('after-processing');
        })
        ->handle(function ($action) {
            $action->recordMemory('before-processing');
            // ... do work ...
            $action->recordMemory('after-processing');
        });
});
```

To profile specific nested actions:

```php
it('profiles nested actions', function () {
    ProcessOrderAction::test()
        ->profile([CalculateTaxAction::class, ApplyDiscountAction::class], function ($profiles) {
            expect($profiles)->toHaveCount(2);
            expect($profiles[0]->class)->toBe(CalculateTaxAction::class);
            expect($profiles[1]->class)->toBe(ApplyDiscountAction::class);
        })
        ->handle($orderData);
});
```

### Combining Features

You can combine multiple testing features in a single test:

```php
it('tracks queries, logs, and performance', function () {
    ProcessOrderAction::test()
        ->queries(function ($queries) {
            expect($queries)->toHaveCount(3);
        })
        ->logs(function ($logs) {
            expect($logs)->toHaveCount(2);
        })
        ->profile(function ($profiles) {
            expect($profiles)->toHaveCount(1);
            expect($profiles[0]->duration()->totalMilliseconds)->toBeLessThan(50);
        })
        ->handle($orderData);
});
```

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Isak Berglind](https://github.com/iaK)
- [All Contributors](https://github.com/iaK/action/contributors)

## Support

If you discover any issues or have questions, please [open an issue](https://github.com/iaK/action/issues).
