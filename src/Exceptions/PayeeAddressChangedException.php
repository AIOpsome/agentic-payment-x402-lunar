<?php

namespace Lunar\CryptoPayments\Exceptions;

class PayeeAddressChangedException extends \RuntimeException
{
    public static function unconfirmed(string $key, string $previousAddress, string $configuredAddress): self
    {
        return new self(
            "Configured lunar-crypto payee [{$key}] changed from [{$previousAddress}] to [{$configuredAddress}] ".
            'without confirmation. Settlement is blocked until an operator explicitly confirms this change: '.
            "php artisan lunar-crypto:confirm-payee {$key}"
        );
    }
}
