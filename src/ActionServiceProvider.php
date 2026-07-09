<?php

namespace Iak\Action;

use Iak\Action\Commands\MakeActionCommand;
use Iak\Action\Execution\MemoizedResults;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ActionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('action')
            ->hasCommand(MakeActionCommand::class);
    }

    public function packageRegistered(): void
    {
        // Scoped, not singleton: Octane workers and the test runner rebuild
        // it whenever the container is flushed, so memoize() never leaks
        // results across requests or tests.
        $this->app->scoped(MemoizedResults::class);
    }
}
