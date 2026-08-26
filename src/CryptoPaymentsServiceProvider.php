<?php

namespace Lunar\CryptoPayments;

use Illuminate\Support\ServiceProvider;
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

        $this->publishes([
            __DIR__.'/../config/crypto.php' => config_path('lunar-crypto.php'),
        ], 'lunar-crypto.config');
    }
}
