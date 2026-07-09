<?php

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

afterEach(function () {
    foreach (['app/Actions', 'app/Domain', 'domain', 'stubs'] as $dir) {
        File::deleteDirectory(base_path($dir));
    }
});

it('creates an action class in app/Actions by default', function () {
    $this->artisan('make:action', ['name' => 'ShipOrder'])
        ->assertExitCode(0);

    $path = base_path('app/Actions/ShipOrder.php');

    expect(File::exists($path))->toBeTrue();

    expect(File::get($path))
        ->toContain('namespace App\Actions;')
        ->toContain('use Iak\Action\Action;')
        ->toContain('class ShipOrder extends Action')
        ->toContain('public function handle()');
});

it('generates into a custom --dir under app with a matching namespace', function () {
    $this->artisan('make:action', ['name' => 'ShipOrder', '--dir' => 'app/Domain/Orders'])
        ->assertExitCode(0);

    expect(File::get(base_path('app/Domain/Orders/ShipOrder.php')))
        ->toContain('namespace App\Domain\Orders;')
        ->toContain('class ShipOrder extends Action');
});

it('derives a studly namespace for a --dir outside the app path', function () {
    $this->artisan('make:action', ['name' => 'ShipOrder', '--dir' => 'domain/actions'])
        ->assertExitCode(0);

    expect(File::get(base_path('domain/actions/ShipOrder.php')))
        ->toContain('namespace Domain\Actions;');
});

it('supports nested names, creating subdirectories and subnamespaces', function () {
    $this->artisan('make:action', ['name' => 'Orders/ShipOrder'])
        ->assertExitCode(0);

    expect(File::get(base_path('app/Actions/Orders/ShipOrder.php')))
        ->toContain('namespace App\Actions\Orders;')
        ->toContain('class ShipOrder extends Action');
});

it('accepts backslash-separated nested names', function () {
    $this->artisan('make:action', ['name' => 'Orders\\ShipOrder'])
        ->assertExitCode(0);

    expect(File::exists(base_path('app/Actions/Orders/ShipOrder.php')))->toBeTrue();
});

it('rejects a name segment that is not a valid PHP class name', function () {
    $this->artisan('make:action', ['name' => 'Ship-Order'])
        ->assertExitCode(1);

    expect(File::exists(base_path('app/Actions/Ship-Order.php')))->toBeFalse();
});

it('rejects an empty name', function () {
    $this->artisan('make:action', ['name' => ''])
        ->assertExitCode(1);
});

it('rejects an empty --dir', function () {
    $this->artisan('make:action', ['name' => 'ShipOrder', '--dir' => ''])
        ->assertExitCode(1);
});

it('refuses to overwrite an existing action', function () {
    File::ensureDirectoryExists(base_path('app/Actions'));
    File::put(base_path('app/Actions/ShipOrder.php'), 'original');

    $this->artisan('make:action', ['name' => 'ShipOrder'])
        ->assertExitCode(1);

    expect(File::get(base_path('app/Actions/ShipOrder.php')))->toBe('original');
});

it('overwrites an existing action with --force', function () {
    File::ensureDirectoryExists(base_path('app/Actions'));
    File::put(base_path('app/Actions/ShipOrder.php'), 'original');

    $this->artisan('make:action', ['name' => 'ShipOrder', '--force' => true])
        ->assertExitCode(0);

    expect(File::get(base_path('app/Actions/ShipOrder.php')))
        ->toContain('class ShipOrder extends Action');
});

it('generates a companion event enum wired through EmitsEvents', function () {
    $this->artisan('make:action', ['name' => 'ShipOrder', '--events' => true])
        ->assertExitCode(0);

    expect(File::get(base_path('app/Actions/ShipOrder.php')))
        ->toContain('use Iak\Action\EmitsEvents;')
        ->toContain('#[EmitsEvents(ShipOrderEvent::class)]')
        ->toContain('class ShipOrder extends Action');

    // The placeholder case is load-bearing: EmitsEvents throws
    // "Events array cannot be empty" for a case-less enum.
    expect(File::get(base_path('app/Actions/ShipOrderEvent.php')))
        ->toContain('namespace App\Actions;')
        ->toContain('enum ShipOrderEvent: string')
        ->toContain("case Started = 'started';");
});

it('writes nothing when only the enum file already exists', function () {
    File::ensureDirectoryExists(base_path('app/Actions'));
    File::put(base_path('app/Actions/ShipOrderEvent.php'), 'original');

    $this->artisan('make:action', ['name' => 'ShipOrder', '--events' => true])
        ->assertExitCode(1);

    expect(File::exists(base_path('app/Actions/ShipOrder.php')))->toBeFalse()
        ->and(File::get(base_path('app/Actions/ShipOrderEvent.php')))->toBe('original');
});

it('prefers an app-published stub in base_path("stubs")', function () {
    File::ensureDirectoryExists(base_path('stubs'));
    File::put(base_path('stubs/action.stub'), "<?php\n\n// custom stub {{ class }}\n");

    $this->artisan('make:action', ['name' => 'ShipOrder'])
        ->assertExitCode(0);

    expect(File::get(base_path('app/Actions/ShipOrder.php')))
        ->toContain('// custom stub ShipOrder');
});

it('generates syntactically valid PHP', function () {
    $this->artisan('make:action', ['name' => 'ShipOrder', '--events' => true])
        ->assertExitCode(0);

    foreach (['ShipOrder.php', 'ShipOrderEvent.php'] as $file) {
        $output = [];
        $status = 1;
        exec('php -l '.escapeshellarg(base_path('app/Actions/'.$file)).' 2>&1', $output, $status);

        Assert::assertSame(0, $status, implode("\n", $output));
    }
});
