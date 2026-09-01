<?php

namespace Lunar\CryptoPayments\X402;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Lunar\CryptoPayments\Actions\AuthorizeCryptoPayment;
use Lunar\CryptoPayments\Actions\BuildPaymentRequirements;
use Lunar\CryptoPayments\Actions\GuardPayeeAddressChange;
use Lunar\CryptoPayments\Actions\ResolvePayeeAddress;
use Lunar\CryptoPayments\Exceptions\PayeeAddressChangedException;
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
        protected GuardPayeeAddressChange $guardPayee,
        protected ResolvePayeeAddress $resolvePayee,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // Every request through here — even a malformed one — costs a
        // facilitator round-trip once past this point (see
        // ValidatePaymentPayload for what's cheap-rejected before that).
        // Rate limit first so that cost can't be amplified by flooding.
        $key = 'lunar-crypto-x402:'.$request->ip();
        [$maxAttempts, $decaySeconds] = $this->parseRateLimit();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json(['error' => 'Too many payment attempts, try again later'], 429);
        }

        RateLimiter::hit($key, $decaySeconds);

        $cart = $request->route('cart');

        if (! $cart instanceof Cart) {
            throw new \RuntimeException(
                'x402 middleware requires a {cart} route parameter bound to a Lunar Cart model.'
            );
        }

        if (! $cart->total) {
            return response()->json(['error' => 'Cart has not been priced yet'], 422);
        }

        // Must run before ANY payTo is built or advertised — even the 402
        // challenge itself. An x402 payload is a broadcastable EIP-3009
        // signature: if a compromised/unconfirmed address ever appears in
        // the challenge's `accepts[].payTo`, an agent can sign against it
        // and settle directly on-chain (or via another facilitator),
        // completely bypassing this package's own settle path — and
        // blocking that later path wouldn't undo an already-broadcast
        // transfer. So this has to gate challenge construction, not just
        // settlement.
        try {
            $this->guardPayee->execute('x402_pay_to', $this->resolvePayee->execute('x402_pay_to', config('lunar-crypto.x402')));
        } catch (PayeeAddressChangedException) {
            // Deliberately not a 402: a 402 payment challenge advertises a
            // payment opportunity, and we don't want to advertise one for
            // an unconfirmed/changed address at all. Detail is already
            // logged critically inside GuardPayeeAddressChange.
            return response()->json([
                'error' => 'Payment configuration requires operator re-confirmation. Contact the store operator.',
            ], 503);
        }

        $requirements = $this->buildRequirements->execute($cart->total, config('lunar-crypto.x402'), 'x402_pay_to');

        $header = $request->header('PAYMENT-SIGNATURE')
            ?? $request->header('Payment-Signature')
            ?? $request->header('X-PAYMENT')
            ?? $request->header('X-Payment');

        if (! $header) {
            $paymentRequired = [
                'x402Version' => 2,
                'resource' => ['url' => $request->fullUrl()],
                'accepts' => [$requirements],
            ];
            $encodedRequired = base64_encode(json_encode($paymentRequired));

            return response()->json([
                'x402Version' => 2,
                'error' => 'Payment Required',
                'resource' => ['url' => $request->fullUrl()],
                'accepts' => [$requirements],
            ], 402, [
                'PAYMENT-REQUIRED' => $encodedRequired,
                'Payment-Required' => $encodedRequired,
                'X-Payment-Requirements' => json_encode($requirements),
            ]);
        }

        $decoded = base64_decode($header, true);
        $payload = json_decode($decoded !== false ? $decoded : $header, true);

        if (! is_array($payload)) {
            $payload = json_decode($header, true);
        }

        if (! is_array($payload)) {
            return response()->json(['error' => 'Malformed payment signature header'], 402);
        }

        $result = $this->authorizeCryptoPayment->execute($cart, $payload, config('lunar-crypto.x402'), null, 'x402_pay_to');

        if (! $result->success) {
            return response()->json(['error' => $result->message], 402);
        }

        $request->attributes->set('lunar_crypto_order', $result->order);

        $response = $next($request);

        if ($result->transaction) {
            $settlementResponse = [
                'success' => true,
                'transaction' => $result->transaction,
                'network' => $result->network,
            ];
            $encodedResponse = base64_encode(json_encode($settlementResponse));
            $response->headers->set('PAYMENT-RESPONSE', $encodedResponse);
            $response->headers->set('Payment-Response', $encodedResponse);
            $response->headers->set('X-Payment-Transaction', $result->transaction);
        }

        return $response;
    }

    /**
     * @return array{0: int, 1: int} [maxAttempts, decaySeconds]
     */
    protected function parseRateLimit(): array
    {
        [$max, $minutes] = array_pad(explode(',', (string) config('lunar-crypto.x402.rate_limit', '30,1')), 2, 1);

        return [max(1, (int) $max), max(1, (int) $minutes) * 60];
    }
}
