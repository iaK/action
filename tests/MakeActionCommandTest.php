<?php

use Illuminate\Support\Facades\File;

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
