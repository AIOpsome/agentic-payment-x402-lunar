<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Lunar\CryptoPayments\Actions\GuardPayeeAddressChange;
use Lunar\CryptoPayments\Exceptions\PayeeAddressChangedException;
use Lunar\CryptoPayments\Models\CryptoPayeeConfig;
use Lunar\CryptoPayments\Models\CryptoSettlement;
use Lunar\CryptoPayments\PaymentTypes\CryptoPaymentType;
use Lunar\Models\Cart;

it('rejects an unknown key', function () {
    $this->artisan('lunar-crypto:confirm-payee', ['key' => 'something_else'])
        ->assertExitCode(1);

    expect(CryptoPayeeConfig::count())->toBe(0);
});

it('fails when the resolved address has nothing configured', function () {
    config()->set('lunar-crypto.pay_to', null);

    $this->artisan('lunar-crypto:confirm-payee', ['key' => 'pay_to'])
        ->assertExitCode(1);

    expect(CryptoPayeeConfig::where('key', 'pay_to')->exists())->toBeFalse();
});

it('confirms a first-ever run with no prior guard-triggered block', function () {
    config()->set('lunar-crypto.pay_to', '0xmerchant');

    $this->artisan('lunar-crypto:confirm-payee', ['key' => 'pay_to'])
        ->assertExitCode(0);

    expect(CryptoPayeeConfig::where('key', 'pay_to')->first()->address)->toBe('0xmerchant');
});

it('is a safe no-op when nothing has changed', function () {
    config()->set('lunar-crypto.pay_to', '0xmerchant');

    CryptoPayeeConfig::create(['key' => 'pay_to', 'address' => '0xmerchant']);

    $this->artisan('lunar-crypto:confirm-payee', ['key' => 'pay_to'])
        ->assertExitCode(0);

    expect(CryptoPayeeConfig::where('key', 'pay_to')->count())->toBe(1)
        ->and(CryptoPayeeConfig::where('key', 'pay_to')->first()->address)->toBe('0xmerchant');
});

it('unblocks a subsequent human checkout after confirming a changed pay_to', function () {
    config()->set('lunar-crypto.pay_to', '0xnewmerchant');
    config()->set('lunar-crypto.network', 'eip155:8453');
    config()->set('lunar-crypto.asset', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
    config()->set('lunar-crypto.pegged_currency', 'USD');
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);

    CryptoPayeeConfig::create(['key' => 'pay_to', 'address' => '0xoldmerchant']);

    // Blocked before confirmation.
    expect(fn () => (new GuardPayeeAddressChange)->execute('pay_to', config('lunar-crypto.pay_to')))
        ->toThrow(PayeeAddressChangedException::class);

    $this->artisan('lunar-crypto:confirm-payee', ['key' => 'pay_to'])
        ->assertExitCode(0);

    expect(CryptoPayeeConfig::where('key', 'pay_to')->first()->address)->toBe('0xnewmerchant');

    $cart = makePricedCart(1000);
    $requiredAmount = (string) ($cart->total->value * 10000);

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'payTo' => '0xnewmerchant',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xnewmerchant',
                'value' => $requiredAmount,
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ];

    Http::fake([
        'facilitator.payai.network/verify' => Http::response(['isValid' => true, 'payer' => '0xpayer']),
        'facilitator.payai.network/settle' => Http::response([
            'success' => true,
            'transaction' => '0xtxhash',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'payer' => '0xpayer',
        ]),
    ]);

    $result = app(CryptoPaymentType::class)
        ->cart($cart)
        ->withData(['payment_payload' => $payload])
        ->authorize();

    expect($result->success)->toBeTrue();

    expect(CryptoSettlement::where('cart_id', $cart->id)->exists())->toBeTrue();
});

it('unblocks the x402 path after confirming x402_pay_to, including the null x402.pay_to fallback to the global pay_to', function () {
    // x402.pay_to left null — the common single-wallet install — so the
    // guard, requirements, and confirm command must all resolve x402_pay_to
    // via the global pay_to fallback, not treat it as "nothing configured".
    config()->set('lunar-crypto.pay_to', '0xnewmerchant');
    config()->set('lunar-crypto.x402.pay_to', null);
    config()->set('lunar-crypto.network', 'eip155:8453');
    config()->set('lunar-crypto.asset', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
    config()->set('lunar-crypto.pegged_currency', 'USD');
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);
    config()->set('lunar-crypto.x402.network', 'eip155:8453');

    CryptoPayeeConfig::create(['key' => 'x402_pay_to', 'address' => '0xoldmerchant']);

    $this->artisan('lunar-crypto:confirm-payee', ['key' => 'x402_pay_to'])
        ->assertExitCode(0);

    expect(CryptoPayeeConfig::where('key', 'x402_pay_to')->first()->address)->toBe('0xnewmerchant');

    Route::bind('cart', fn ($value) => Cart::findOrFail($value));

    Route::post('/x402/carts/{cart}/checkout', fn () => response()->json(['ok' => true]))
        ->middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class, 'x402']);

    $cart = makePricedCart(1000);
    $requiredAmount = (string) ($cart->total->value * 10000);

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'payTo' => '0xnewmerchant',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xnewmerchant',
                'value' => $requiredAmount,
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ];

    Http::fake([
        'facilitator.payai.network/verify' => Http::response(['isValid' => true, 'payer' => '0xpayer']),
        'facilitator.payai.network/settle' => Http::response([
            'success' => true,
            'transaction' => '0xtxhash',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'payer' => '0xpayer',
        ]),
    ]);

    $header = base64_encode(json_encode($payload));

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout", [], ['X-PAYMENT' => $header]);

    $response->assertStatus(200);

    expect(CryptoSettlement::where('cart_id', $cart->id)->exists())->toBeTrue();
});
