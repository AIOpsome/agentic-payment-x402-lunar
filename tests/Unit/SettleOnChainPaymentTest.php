<?php

use Illuminate\Support\Facades\Http;
use Lunar\CryptoPayments\Actions\SettleOnChainPayment;
use Lunar\CryptoPayments\Facilitators\FacilitatorNotSupportedException;

function samplePayload(): array
{
    return [
        'x402Version' => 2,
        'accepted' => sampleRequirements(),
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xmerchant',
                'value' => '10000',
                'validAfter' => '1740672089',
                'validBefore' => '1740672154',
                'nonce' => '0xnonce',
            ],
        ],
    ];
}

function sampleRequirements(): array
{
    return [
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'amount' => '10000',
        'asset' => '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913',
        'payTo' => '0xmerchant',
        'maxTimeoutSeconds' => 60,
    ];
}

beforeEach(function () {
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);
});

it('settles successfully when the facilitator verifies and settles', function () {
    Http::fake([
        'facilitator.payai.network/verify' => Http::response(['isValid' => true, 'payer' => '0xpayer']),
        'facilitator.payai.network/settle' => Http::response([
            'success' => true,
            'transaction' => '0xtxhash',
            'network' => 'eip155:8453',
            'payer' => '0xpayer',
        ]),
    ]);

    $result = (new SettleOnChainPayment)->execute(samplePayload(), sampleRequirements());

    expect($result->success)->toBeTrue()
        ->and($result->txHash)->toBe('0xtxhash')
        ->and($result->settledAmount)->toBe(10000)
        ->and($result->payer)->toBe('0xpayer')
        ->and($result->facilitator)->toBe('payai');
});

it('fails without calling settle when verification fails', function () {
    Http::fake([
        'facilitator.payai.network/verify' => Http::response(['isValid' => false, 'invalidReason' => 'insufficient_funds']),
        'facilitator.payai.network/settle' => Http::response(['success' => true, 'transaction' => '0xshouldnothappen']),
    ]);

    $result = (new SettleOnChainPayment)->execute(samplePayload(), sampleRequirements());

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('insufficient_funds');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/settle'));
});

it('falls through to the next facilitator when the first errors', function () {
    config()->set('lunar-crypto.facilitators.coinbase_testnet.url', 'https://x402.org/facilitator');
    config()->set('lunar-crypto.facilitator_order', ['payai', 'coinbase_testnet']);

    Http::fake([
        'facilitator.payai.network/*' => Http::response([], 500),
        'x402.org/facilitator/verify' => Http::response(['isValid' => true]),
        'x402.org/facilitator/settle' => Http::response(['success' => true, 'transaction' => '0xfallback']),
    ]);

    $result = (new SettleOnChainPayment)->execute(samplePayload(), sampleRequirements());

    expect($result->success)->toBeTrue()
        ->and($result->txHash)->toBe('0xfallback');
});

it('throws when the only configured facilitator requires unimplemented auth', function () {
    config()->set('lunar-crypto.facilitators.coinbase_cdp', [
        'url' => 'https://api.cdp.coinbase.com/platform/v2/x402',
        'api_key_secret' => 'secret',
    ]);
    config()->set('lunar-crypto.facilitator_order', ['coinbase_cdp']);

    (new SettleOnChainPayment)->execute(samplePayload(), sampleRequirements());
})->throws(FacilitatorNotSupportedException::class);
