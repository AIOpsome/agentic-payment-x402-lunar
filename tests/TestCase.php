<?php

namespace Lunar\CryptoPayments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    // Deliberately not registering CryptoPaymentsServiceProvider here: it
    // boots against Lunar\Facades\Payments, which needs lunarphp/core's own
    // provider registered first. Unit tests below exercise
    // SettleOnChainPayment directly and don't need the provider booted.
}
