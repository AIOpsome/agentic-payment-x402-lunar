<?php

use Lunar\CryptoPayments\Actions\ConvertToAssetUnits;

it('rescales a 2-decimal currency amount to 6-decimal USDC atomic units', function () {
    // $10.00 order (1000 cents) -> 10_000_000 USDC atomic units (6 decimals).
    $amount = (new ConvertToAssetUnits)->execute(
        value: 1000,
        currencyDecimals: 2,
        assetDecimals: 6,
        currencyCode: 'USD',
        peggedCurrency: 'USD',
    );

    expect($amount)->toBe('10000000');
});

it('is case-insensitive when matching the pegged currency', function () {
    $amount = (new ConvertToAssetUnits)->execute(
        value: 500,
        currencyDecimals: 2,
        assetDecimals: 6,
        currencyCode: 'usd',
        peggedCurrency: 'USD',
    );

    expect($amount)->toBe('5000000');
});

it('rescales down when the asset has fewer decimals than the currency', function () {
    $amount = (new ConvertToAssetUnits)->execute(
        value: 123456,
        currencyDecimals: 4,
        assetDecimals: 2,
        currencyCode: 'USD',
        peggedCurrency: 'USD',
    );

    expect($amount)->toBe('1234');
});

it('refuses to convert a non-pegged currency rather than silently mispricing', function () {
    (new ConvertToAssetUnits)->execute(
        value: 1000,
        currencyDecimals: 2,
        assetDecimals: 6,
        currencyCode: 'EUR',
        peggedCurrency: 'USD',
    );
})->throws(RuntimeException::class, 'no FX conversion');
