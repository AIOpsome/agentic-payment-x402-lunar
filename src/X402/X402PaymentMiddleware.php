<?php

namespace Lunar\CryptoPayments\X402;

use Closure;
use Illuminate\Http\Request;
use Lunar\CryptoPayments\Actions\AuthorizeCryptoPayment;
use Lunar\CryptoPayments\Actions\BuildPaymentRequirements;
use Lunar\Models\Contracts\Cart;

/**
 * Wraps a cart-checkout route for agent payments. Not a cart/order-bound
 * PaymentTypeInterface implementation — x402 is an HTTP 402 challenge on a
 * route, not something Lunar's checkout flow invokes — but it settles into
 * exactly the same place as human checkout: a cart, resolved via Laravel
 * route-model binding, gets its own order via AuthorizeCryptoPayment. The
 * consuming app builds/prices the cart itself (its own product/checkout
 * API) before ever hitting an x402-protected route — this middleware only
 * owns "is this cart paid for", the same boundary CryptoPaymentType has for
 * human checkout.
 *
 * Route example:
 *   Route::post('/x402/carts/{cart}/checkout', CompleteCheckout::class)
 *       ->middleware('x402');
 */
class X402PaymentMiddleware
{
    public function __construct(
        protected BuildPaymentRequirements $buildRequirements,
        protected AuthorizeCryptoPayment $authorizeCryptoPayment,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $cart = $request->route('cart');

        if (! $cart instanceof Cart) {
            throw new \RuntimeException(
                'x402 middleware requires a {cart} route parameter bound to a Lunar Cart model.'
            );
        }

        if (! $cart->total) {
            return response()->json(['error' => 'Cart has not been priced yet'], 422);
        }

        $requirements = $this->buildRequirements->execute($cart->total, config('lunar-crypto.x402'));

        $header = $request->header('X-PAYMENT');

        if (! $header) {
            return response()->json([
                'x402Version' => 2,
                'error' => 'X-PAYMENT header is required',
                'resource' => ['url' => $request->fullUrl()],
                'accepts' => [$requirements],
            ], 402);
        }

        $payload = json_decode(base64_decode($header), true);

        if (! $payload) {
            return response()->json(['error' => 'Malformed X-PAYMENT header'], 402);
        }

        $result = $this->authorizeCryptoPayment->execute($cart, $payload, config('lunar-crypto.x402'));

        if (! $result->success) {
            return response()->json(['error' => $result->message], 402);
        }

        $request->attributes->set('lunar_crypto_order', $result->order);

        return $next($request);
    }
}
