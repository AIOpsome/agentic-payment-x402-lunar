<?php

use Lunar\CryptoPayments\Actions\GuardPayeeAddressChange;
use Lunar\CryptoPayments\Exceptions\PayeeAddressChangedException;
use Lunar\CryptoPayments\Models\CryptoPayeeConfig;

it('accepts a first-time pay_to with no prior record, and records it', function () {
    (new GuardPayeeAddressChange)->execute('pay_to', '0xmerchant');

    expect(CryptoPayeeConfig::where('key', 'pay_to')->first()->address)->toBe('0xmerchant');
});

it('does not trigger on an unchanged pay_to across repeated calls', function () {
    $guard = new GuardPayeeAddressChange;

    $guard->execute('pay_to', '0xmerchant');
    $guard->execute('pay_to', '0xmerchant');
    $guard->execute('pay_to', '0xMERCHANT'); // case-insensitive

    expect(CryptoPayeeConfig::where('key', 'pay_to')->count())->toBe(1);
});

it('throws and blocks when pay_to changes from a previously recorded value', function () {
    $guard = new GuardPayeeAddressChange;

    $guard->execute('pay_to', '0xmerchant');

    expect(fn () => $guard->execute('pay_to', '0xattacker'))
        ->toThrow(PayeeAddressChangedException::class);

    // The stored record is untouched by the blocked attempt.
    expect(CryptoPayeeConfig::where('key', 'pay_to')->first()->address)->toBe('0xmerchant');
});

it('guards pay_to and x402_pay_to independently', function () {
    $guard = new GuardPayeeAddressChange;

    $guard->execute('pay_to', '0xmerchant-human');
    $guard->execute('x402_pay_to', '0xmerchant-x402');

    expect(CryptoPayeeConfig::count())->toBe(2);
});

it('ignores a null configured address', function () {
    (new GuardPayeeAddressChange)->execute('pay_to', null);

    expect(CryptoPayeeConfig::where('key', 'pay_to')->exists())->toBeFalse();
});
