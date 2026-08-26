<?php

use Lunar\CryptoPayments\Actions\ValidateCryptoConfig;

it('allows a known-good asset address for the network', function () {
    (new ValidateCryptoConfig)->execute('eip155:8453', '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913');
})->throwsNoExceptions();

it('is case-insensitive when comparing addresses', function () {
    (new ValidateCryptoConfig)->execute('eip155:8453', '0X833589FCD6EDB6E08F4C7C32D4F71B54BDA02913');
})->throwsNoExceptions();

it('throws on a testnet asset configured for a mainnet network', function () {
    (new ValidateCryptoConfig)->execute('eip155:8453', '0x036CbD53842c5426634e7929541eC2318f3dCF7e');
})->throws(RuntimeException::class, 'testnet/mainnet mismatch');

it('allows an unrecognized network through unchecked', function () {
    (new ValidateCryptoConfig)->execute('eip155:1', '0xanything');
})->throwsNoExceptions();
