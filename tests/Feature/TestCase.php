<?php

namespace Lunar\CryptoPayments\Tests\Feature;

use Cartalyst\Converter\Laravel\ConverterServiceProvider;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Lunar\CryptoPayments\CryptoPaymentsServiceProvider;
use Lunar\LunarServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

/**
 * Boots a real Lunar application (in-memory sqlite) — the same providers
 * and migrations a real consuming app would register. Feature tests build
 * an actual Currency/Channel/Cart/Order graph and drive CryptoPaymentType
 * end to end, not just the pure-logic Actions.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LunarServiceProvider::class,
            BlinkServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            ConverterServiceProvider::class,
            NestedSetServiceProvider::class,
            CryptoPaymentsServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadLaravelMigrations();

        // Both LunarServiceProvider and CryptoPaymentsServiceProvider
        // register their own migration paths in boot(), but Testbench
        // doesn't reliably pick those up from arbitrary package providers —
        // only from paths registered here.
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/lunarphp/core/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
