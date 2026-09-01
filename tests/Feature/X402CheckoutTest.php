<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Lunar\Models\Cart;

beforeEach(function () {
    config()->set('lunar-crypto.network', 'eip155:8453');
    // Deliberately not setting lunar-crypto.asset: these tests exercise the
    // same per-network resolution a real deployment gets.
    config()->set('lunar-crypto.pegged_currency', 'USD');
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);
    config()->set('lunar-crypto.x402.network', 'eip155:8453');
    config()->set('lunar-crypto.x402.pay_to', '0xmerchant');

    Route::bind('cart', fn ($value) => Cart::findOrFail($value));

    Route::post('/x402/carts/{cart}/checkout', fn () => response()->json(['ok' => true]))
        ->middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class, 'x402']);
});

it('responds 402 with payment requirements and PAYMENT-REQUIRED header when no header is sent', function () {
    $cart = makePricedCart(1000);

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout");

    $response->assertStatus(402);
    // v2 puts everything in the header; the body is empty.
    expect($response->getContent())->toBe('{}');

    $header = $response->headers->get('PAYMENT-REQUIRED');
    expect($header)->not->toBeNull();
    $decoded = json_decode(base64_decode($header), true);
    expect($decoded['x402Version'])->toBe(2)
        ->and($decoded['error'])->toBe('Payment Required')
        ->and($decoded['accepts'][0]['amount'])->toBe((string) ($cart->total->value * 10000))
        ->and($decoded['accepts'][0]['payTo'])->not->toBeNull();
});

it('uses the Base Sepolia USDC contract when the x402 network is Sepolia', function () {
    config()->set('lunar-crypto.x402.network', 'eip155:84532');

    $cart = makePricedCart(1000);

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout");

    $response->assertStatus(402);
    $decoded = json_decode(base64_decode($response->headers->get('PAYMENT-REQUIRED')), true);

    expect($decoded['accepts'][0]['network'])->toBe('eip155:84532')
        ->and($decoded['accepts'][0]['asset'])->toBe('0x036CbD53842c5426634e7929541eC2318f3dCF7e');
});

it('rejects a structurally-empty payload with a re-quotable 402 before creating an order', function ($payload) {
    $cart = makePricedCart(1000);

    Http::fake();

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout", [], [
        'PAYMENT-SIGNATURE' => base64_encode(json_encode($payload)),
    ]);

    $response->assertStatus(402);

    $decoded = json_decode(base64_decode($response->headers->get('PAYMENT-REQUIRED')), true);
    expect($decoded['error'])->toBe('Malformed PAYMENT-SIGNATURE header')
        ->and($decoded['accepts'][0]['amount'])->toBe((string) ($cart->total->value * 10000));

    Http::assertNothingSent();
    expect($cart->fresh()->completedOrder)->toBeNull()
        ->and(\Lunar\Models\Order::count())->toBe(0);
})->with([
    'empty object' => [[]],
    'garbage list' => [[1, 2, 3]],
    'accepted only' => [['accepted' => ['scheme' => 'exact']]],
]);

it('returns fresh payment requirements in the PAYMENT-REQUIRED header when authorization fails', function () {
    $cart = makePricedCart(1000);

    Http::fake([
        'facilitator.payai.network/verify' => Http::response(['isValid' => false, 'invalidReason' => 'nope']),
    ]);

    // Structurally complete, but the signed amount is wrong, so
    // ValidatePaymentPayload rejects it inside AuthorizeCryptoPayment.
    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => '1',
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'payTo' => config('lunar-crypto.x402.pay_to'),
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => config('lunar-crypto.x402.pay_to'),
                'value' => '1',
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ];

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout", [], [
        'PAYMENT-SIGNATURE' => base64_encode(json_encode($payload)),
    ]);

    $response->assertStatus(402);
    expect($response->getContent())->toBe('{}');

    $header = $response->headers->get('PAYMENT-REQUIRED');
    expect($header)->not->toBeNull();
    $decoded = json_decode(base64_decode($header), true);
    expect($decoded['x402Version'])->toBe(2)
        ->and($decoded['error'])->not->toBeEmpty()
        ->and($decoded['accepts'][0]['amount'])->toBe((string) ($cart->total->value * 10000));
});

it('settles and lets the request through with a valid PAYMENT-SIGNATURE header', function () {
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

    $response = $this->postJson("/x402/carts/{$cart->id}/checkout", [], ['PAYMENT-SIGNATURE' => $header]);

    $response->assertStatus(200)->assertJson(['ok' => true]);

    $responseHeader = $response->headers->get('PAYMENT-RESPONSE');
    expect($responseHeader)->not->toBeNull();
    $decodedResponse = json_decode(base64_decode($responseHeader), true);
    expect($decodedResponse['transaction'])->toBe('0xagenttx');

    expect($cart->fresh()->completedOrder)->not->toBeNull()
        ->and($cart->fresh()->completedOrder->placed_at)->not->toBeNull();
});

it('settles and lets the request through with a legacy X-PAYMENT header for backwards compatibility', function () {
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
