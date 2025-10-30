<?php

use Iak\Action\ActionServiceProvider;
use Orchestra\Testbench\TestCase;

class ActionServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            ActionServiceProvider::class,
        ];
    }

    public function test_can_configure_package()
    {
        $provider = new ActionServiceProvider($this->app);
        
        $package = new \Spatie\LaravelPackageTools\Package('test-package');
        
        // This should not throw an exception
        $provider->configurePackage($package);
        
        $this->assertTrue(true);
    }
}
