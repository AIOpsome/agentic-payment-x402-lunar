<?php

namespace Lunar\CryptoPayments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    // Deliberately not registering any Lunar providers here — Unit tests
    // exercise pure-logic Actions (SettleOnChainPayment, ConvertToAssetUnits,
    // ValidatePaymentPayload, ValidateCryptoConfig) that never touch
    // Eloquent, so they don't need the full boot Feature/TestCase.php
    // requires. Keeps this suite fast and independent of Lunar core.
}
