<?php

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
