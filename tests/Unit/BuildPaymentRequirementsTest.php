<?php

use Lunar\CryptoPayments\Actions\BuildPaymentRequirements;
use Lunar\CryptoPayments\Actions\ConvertToAssetUnits;
use Lunar\CryptoPayments\Actions\ResolvePayeeAddress;
use Lunar\DataTypes\Price;
use Lunar\Models\Currency;

it('defaults to Base mainnet USDC when network is eip155:8453', function () {
    $currency = Mockery::mock(\Lunar\Models\Contracts\Currency::class);
    $currency->decimal_places = 2;
    $currency->code = 'USD';
    $price = new Price(1000, $currency, 1);

    $convert = new ConvertToAssetUnits;
    $resolvePayee = new ResolvePayeeAddress;
    $action = new BuildPaymentRequirements($convert, $resolvePayee);
    $reqs = $action->execute($price, ['network' => 'eip155:8453', 'pay_to' => '0xmerchant']);

    expect($reqs['network'])->toBe('eip155:8453')
        ->and($reqs['asset'])->toBe('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
});

it('defaults to Base Sepolia USDC when network is eip155:84532', function () {
    $currency = Mockery::mock(\Lunar\Models\Contracts\Currency::class);
    $currency->decimal_places = 2;
    $currency->code = 'USD';
    $price = new Price(1000, $currency, 1);

    $convert = new ConvertToAssetUnits;
    $resolvePayee = new ResolvePayeeAddress;
    $action = new BuildPaymentRequirements($convert, $resolvePayee);
    $reqs = $action->execute($price, ['network' => 'eip155:84532', 'pay_to' => '0xmerchant']);

    expect($reqs['network'])->toBe('eip155:84532')
        ->and($reqs['asset'])->toBe('0x036CbD53842c5426634e7929541eC2318f3dCF7e');
});
