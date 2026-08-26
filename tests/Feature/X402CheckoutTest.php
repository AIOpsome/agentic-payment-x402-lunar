<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Lunar\Models\Cart;

beforeEach(function () {
    config()->set('lunar-crypto.network', 'eip155:8453');
    config()->set('lunar-crypto.asset', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
    config()->set('lunar-crypto.pegged_currency', 'USD');
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);
    config()->set('lunar-crypto.x402.network', 'eip155:8453');
    config()->set('lunar-crypto.x402.pay_to', '0xmerchant');

    Route::bind('cart', fn ($value) => Cart::findOrFail($value));

    Route::post('/x402/carts/{cart}/checkout', fn () => response()->json(['ok' => true]))
        ->middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class, 'x402']);
});

it('responds 402 with payment requirements when no X-PAYMENT header is sent', function () {
    $cart = makePricedCart(1000);

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout");

    $response->assertStatus(402);
    expect($response->json('accepts.0.amount'))->toBe((string) ($cart->total->value * 10000))
        ->and($response->json('accepts.0.payTo'))->not->toBeNull();
});

it('settles and lets the request through with a valid X-PAYMENT header', function () {
    $cart = makePricedCart(1000);
    $requiredAmount = (string) ($cart->total->value * 10000);

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'payTo' => config('lunar-crypto.x402.pay_to'),
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => config('lunar-crypto.x402.pay_to'),
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
            'transaction' => '0xagenttx',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'payer' => '0xpayer',
        ]),
    ]);

    $header = base64_encode(json_encode($payload));

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout", [], ['X-PAYMENT' => $header]);

    $response->assertStatus(200)->assertJson(['ok' => true]);

    expect($cart->fresh()->completedOrder)->not->toBeNull()
        ->and($cart->fresh()->completedOrder->placed_at)->not->toBeNull();
});

it('rate-limits repeated requests for the same IP before touching the facilitator', function () {
    $cart = makePricedCart(1000);

    config()->set('lunar-crypto.x402.rate_limit', '2,1');

    Http::fake();

    $this->postJson("/x402/carts/{$cart->id}/checkout")->assertStatus(402);
    $this->postJson("/x402/carts/{$cart->id}/checkout")->assertStatus(402);
    $this->postJson("/x402/carts/{$cart->id}/checkout")->assertStatus(429);

    Http::assertNothingSent();
});
