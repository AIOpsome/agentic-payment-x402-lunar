<?php

namespace Lunar\CryptoPayments;

use Illuminate\Support\ServiceProvider;
use Lunar\CryptoPayments\Actions\ValidateCryptoConfig;
use Lunar\CryptoPayments\PaymentTypes\CryptoPaymentType;
use Lunar\CryptoPayments\X402\X402PaymentMiddleware;
use Lunar\Facades\Payments;

class CryptoPaymentsServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        Payments::extend('crypto', function ($app) {
            return $app->make(CryptoPaymentType::class);
        });

        $this->app['router']->aliasMiddleware('x402', X402PaymentMiddleware::class);

        $this->mergeConfigFrom(__DIR__.'/../config/crypto.php', 'lunar-crypto');

        // Fail loud at boot on a testnet/mainnet asset-network mismatch,
        // rather than silently at the first checkout attempt. Checked for
        // both the human-checkout network and the (potentially different)
        // x402 network — both share the same configured asset.
        $validateConfig = new ValidateCryptoConfig;
        $validateConfig->execute(config('lunar-crypto.network'), config('lunar-crypto.asset'));
        $validateConfig->execute(config('lunar-crypto.x402.network'), config('lunar-crypto.asset'));

        $this->publishes([
            __DIR__.'/../config/crypto.php' => config_path('lunar-crypto.php'),
        ], 'lunar-crypto.config');

        if (! config('lunar.database.disable_migrations', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
