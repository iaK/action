# Laravel Action

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

## Testing

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
