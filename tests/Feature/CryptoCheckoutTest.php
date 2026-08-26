<?php

use Illuminate\Support\Facades\Http;
use Lunar\CryptoPayments\PaymentTypes\CryptoPaymentType;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\CartLine;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Language;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;

function makePricedCart(int $unitPrice = 1000): Cart
{
    Language::factory()->create(['default' => true]);
    $currency = Currency::factory()->create(['code' => 'USD', 'decimal_places' => 2, 'default' => true]);
    $channel = Channel::factory()->create(['default' => true]);

    // Not shippable, to keep this test focused on the payment flow rather
    // than also having to set up a ShippingOption/shipping address.
    $variant = ProductVariant::factory()->create(['shippable' => false]);

    Price::factory()->create([
        'priceable_type' => ProductVariant::morphName(),
        'priceable_id' => $variant->id,
        'currency_id' => $currency->id,
        'price' => $unitPrice,
        'min_quantity' => 1,
    ]);

    $cart = Cart::factory()->create([
        'currency_id' => $currency->id,
        'channel_id' => $channel->id,
    ]);

    CartLine::factory()->create([
        'cart_id' => $cart->id,
        'purchasable_type' => ProductVariant::morphName(),
        'purchasable_id' => $variant->id,
        'quantity' => 1,
    ]);

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'billing',
    ]);

    return $cart->calculate()->fresh();
}

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

    expect($order->placed_at)->not->toBeNull()
        ->and($order->transactions()->where('driver', 'crypto')->exists())->toBeTrue();

    $settlement = \Lunar\CryptoPayments\Models\CryptoSettlement::where('order_id', $order->id)->first();

    expect($settlement)->not->toBeNull()
        ->and($settlement->status)->toBe('recorded')
        ->and($settlement->tx_hash)->toBe('0xrealtxhash');
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
