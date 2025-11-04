# Laravel Action

[![Latest Version on Packagist](https://img.shields.io/packagist/v/iak/action.svg?style=flat-square)](https://packagist.org/packages/iak/laravel-action)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/iak/action/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/iak/action/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/iak/action/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/iak/action/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/iak/action.svg?style=flat-square)](https://packagist.org/packages/iak/action)

A simple way to organize your business logic in Laravel applications.

## Installation

```bash
composer require iak/action
```

## Basic Usage

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

## Static Methods

Actions provide helpful static methods:

```php
// Create an instance
$action = SayHelloAction::make();

// Create a fake for testing
$action = SayHelloAction::fake();

// Create a testable action to help test logs, performance, database queries and more
$action = SayHelloAction::test();
```

## Events

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

### Forwarding Events

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
- When `forwardEvents()` is called on an action, events emitted by that action will bubble up through the call stack to the first class that uses the `HandlesEvents` trait
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

## Testing

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
            expect($profiles[0]->memoryUsed())->toBeGreaterThan(0);
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

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Isak Berglind](https://github.com/iak)
- [All Contributors](../../contributors)

## Support

If you discover any issues or have questions, please [open an issue](https://github.com/iak/laravel_action/issues).
