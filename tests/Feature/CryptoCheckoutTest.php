<?php

use Illuminate\Support\Facades\Http;
use Lunar\CryptoPayments\PaymentTypes\CryptoPaymentType;
use Lunar\Models\Cart;

beforeEach(function () {
    config()->set('lunar-crypto.pay_to', '0xmerchant');
    config()->set('lunar-crypto.network', 'eip155:8453');
    config()->set('lunar-crypto.asset', '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913');
    config()->set('lunar-crypto.pegged_currency', 'USD');
    config()->set('lunar-crypto.facilitators.payai.url', 'https://facilitator.payai.network');
    config()->set('lunar-crypto.facilitator_order', ['payai']);
});

it('settles a real cart end to end into a placed order', function () {
    $cart = makePricedCart(1000);

    expect($cart->total->value)->toBeGreaterThan(0);

    $requiredAmount = (string) ($cart->total->value * 10000); // 2 decimal -> 6 decimal USDC

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'asset' => '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913',
            'payTo' => '0xmerchant',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xmerchant',
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
            'transaction' => '0xrealtxhash',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'payer' => '0xpayer',
        ]),
    ]);

    $result = app(CryptoPaymentType::class)
        ->cart($cart)
        ->withData(['payment_payload' => $payload])
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->orderId)->not->toBeNull();

    $order = \Lunar\Models\Order::find($result->orderId);

    expect($order->placed_at)->not->toBeNull();

    $transaction = $order->transactions()->where('driver', 'crypto')->first();

    expect($transaction)->not->toBeNull()
        // Regression guard: the recorded transaction amount must be in the
        // store currency's minor unit (matching the order total), NOT the
        // asset's atomic-unit settled amount (10000 x the order total here)
        // — those are a different scale entirely.
        ->and($transaction->amount->value)->toBe($order->total->value)
        ->and($transaction->card_type)->toBe('crypto');

    $settlement = \Lunar\CryptoPayments\Models\CryptoSettlement::where('order_id', $order->id)->first();

    expect($settlement)->not->toBeNull()
        ->and($settlement->status)->toBe('recorded')
        ->and($settlement->tx_hash)->toBe('0xrealtxhash')
        ->and($settlement->amount)->toBe($order->total->value);
});

it('does not settle twice on a retried authorize call', function () {
    $cart = makePricedCart(500);
    $requiredAmount = (string) ($cart->total->value * 10000);

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'asset' => '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913',
            'payTo' => '0xmerchant',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xmerchant',
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
            'transaction' => '0xretrytx',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'payer' => '0xpayer',
        ]),
    ]);

    // Re-fetch the cart fresh for each attempt (as a real retried HTTP
    // request would) rather than reusing the same in-memory object, whose
    // cached relations wouldn't reflect what the first attempt just wrote.
    $paymentType = fn () => app(CryptoPaymentType::class)->cart(Cart::find($cart->id))->withData(['payment_payload' => $payload]);

    $first = $paymentType()->authorize();
    $second = $paymentType()->authorize();

    expect($first->success)->toBeTrue()
        ->and($second->success)->toBeTrue()
        ->and($second->orderId)->toBe($first->orderId);

    Http::assertSentCount(2); // exactly one verify + one settle, not four
});

it('rejects a payload whose signed amount is lower than the cart total (fraud attempt)', function () {
    $cart = makePricedCart(1000);
    $requiredAmount = (string) ($cart->total->value * 10000);

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount, // claims to match
            'asset' => '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913',
            'payTo' => '0xmerchant',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xmerchant',
                'value' => '1', // but actually only signed for 1 atomic unit
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ];

    Http::fake(); // no facilitator call should happen at all

    $result = app(CryptoPaymentType::class)
        ->cart($cart)
        ->withData(['payment_payload' => $payload])
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('does not match the required amount');

    Http::assertNothingSent();
});

it('refuses to settle a cart while another attempt for it holds the lock', function () {
    $cart = makePricedCart(1000);
    $requiredAmount = (string) ($cart->total->value * 10000);

    $payload = [
        'x402Version' => 2,
        'accepted' => [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => $requiredAmount,
            'asset' => '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913',
            'payTo' => '0xmerchant',
            'maxTimeoutSeconds' => 60,
        ],
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xmerchant',
                'value' => $requiredAmount,
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ];

    Http::fake(); // holding the lock should stop this from ever being reached

    // Simulates a second concurrent request arriving mid-settlement: the
    // lock is already held by "someone else" for this cart when authorize()
    // is called.
    $lock = \Illuminate\Support\Facades\Cache::lock("lunar-crypto-settlement:{$cart->id}", 30);
    $lock->get();

    try {
        $result = app(CryptoPaymentType::class)
            ->cart($cart)
            ->withData(['payment_payload' => $payload])
            ->authorize();

        expect($result->success)->toBeFalse()
            ->and($result->message)->toContain('already in progress');

        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
})->group('slow'); // blocks for real, waiting out the lock's 5s acquire timeout

it('fails gracefully instead of throwing when a refund is attempted', function () {
    $cart = makePricedCart(1000);
    $order = $cart->createOrder();

    $transaction = $order->transactions()->create([
        'success' => true,
        'type' => 'capture',
        'driver' => 'crypto',
        'amount' => $order->total->value,
        'reference' => '0xsometx',
        'status' => 'settled',
        'card_type' => 'crypto',
        'last_four' => '',
    ]);

    $result = app(CryptoPaymentType::class)->refund($transaction, $order->total->value);

    expect($result)->toBeInstanceOf(\Lunar\Base\DataTransferObjects\PaymentRefund::class)
        ->and($result->success)->toBeFalse()
        ->and($result->message)->toContain('not yet supported');
});
