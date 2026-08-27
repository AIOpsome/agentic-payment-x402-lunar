<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Lunar\CryptoPayments\Actions\GuardPayeeAddressChange;
use Lunar\CryptoPayments\Exceptions\PayeeAddressChangedException;
use Lunar\CryptoPayments\Models\CryptoPayeeConfig;
use Lunar\CryptoPayments\Models\CryptoSettlement;
use Lunar\CryptoPayments\PaymentTypes\CryptoPaymentType;
use Lunar\Models\Cart;

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

it('blocks settlement end to end through human checkout when pay_to has changed since it was last confirmed', function () {
    config()->set('lunar-crypto.pay_to', '0xattacker');
    config()->set('lunar-crypto.network', 'eip155:8453');
    config()->set('lunar-crypto.asset', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
    config()->set('lunar-crypto.pegged_currency', 'USD');
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);

    // A previously-confirmed pay_to on file, different from what's
    // currently configured — simulates a compromised/mistyped .env.
    CryptoPayeeConfig::create(['key' => 'pay_to', 'address' => '0xmerchant']);

    $cart = makePricedCart(1000);
    $requiredAmount = (string) ($cart->total->value * 10000);

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'payTo' => '0xattacker',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xattacker',
                'value' => $requiredAmount,
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ];

    // No facilitator response is faked — if the guard fails to block this,
    // the settle call itself would throw, still failing the assertion below.
    Http::fake();

    $result = app(CryptoPaymentType::class)
        ->cart($cart)
        ->withData(['payment_payload' => $payload])
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('operator re-confirmation')
        ->and($result->message)->not->toContain('0xattacker')
        ->and($result->message)->not->toContain('0xmerchant');

    Http::assertNothingSent();

    expect(CryptoSettlement::where('cart_id', $cart->id)->exists())->toBeFalse()
        ->and(CryptoPayeeConfig::where('key', 'pay_to')->first()->address)->toBe('0xmerchant');
});

it('blocks settlement end to end through the x402 middleware when x402_pay_to has changed since it was last confirmed', function () {
    config()->set('lunar-crypto.network', 'eip155:8453');
    config()->set('lunar-crypto.asset', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
    config()->set('lunar-crypto.pegged_currency', 'USD');
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);
    config()->set('lunar-crypto.x402.network', 'eip155:8453');
    config()->set('lunar-crypto.x402.pay_to', '0xattacker');

    CryptoPayeeConfig::create(['key' => 'x402_pay_to', 'address' => '0xmerchant']);

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
            'payTo' => '0xattacker',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xattacker',
                'value' => $requiredAmount,
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ];

    Http::fake();

    $header = base64_encode(json_encode($payload));

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout", [], ['X-PAYMENT' => $header]);

    // Not 402: the guard now blocks before the 402 challenge (containing
    // payTo) is ever built, so a changed/unconfirmed payee never gets a
    // payment challenge advertised for it at all.
    $response->assertStatus(503)
        ->assertJson(['error' => 'Payment configuration requires operator re-confirmation. Contact the store operator.']);

    Http::assertNothingSent();

    expect(CryptoSettlement::where('cart_id', $cart->id)->exists())->toBeFalse()
        ->and($cart->fresh()->completedOrder)->toBeNull();
});
