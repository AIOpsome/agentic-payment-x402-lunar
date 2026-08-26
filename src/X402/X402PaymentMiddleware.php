<?php

namespace Lunar\CryptoPayments\X402;

use Closure;
use Illuminate\Http\Request;
use Lunar\CryptoPayments\Actions\SettleOnChainPayment;

/**
 * Wraps an agent-facing route (not a cart/order-bound checkout flow, so this
 * does not implement Lunar's PaymentTypeInterface). On first request with no
 * X-PAYMENT header, responds 402 with payment requirements. On a request
 * carrying a signed X-PAYMENT header, settles it via the shared core and,
 * on success, lets the request through.
 */
class X402PaymentMiddleware
{
    public function __construct(protected SettleOnChainPayment $settle) {}

    public function handle(Request $request, Closure $next, string $amount, ?string $asset = null)
    {
        $requirements = [
            'scheme' => 'exact',
            'network' => config('lunar-crypto.x402.network'),
            'amount' => $amount,
            'asset' => $asset ?? config('lunar-crypto.asset'),
            'payTo' => config('lunar-crypto.x402.pay_to'),
            'maxTimeoutSeconds' => 60,
        ];

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

        $result = $this->settle->execute($payload, $requirements);

        if (! $result->success) {
            return response()->json(['error' => $result->message], 402);
        }

        // Order creation for the agent flow is deliberately out of scope for
        // this spike — depends on how the target route maps a request to a
        // cart (product API vs. arbitrary priced endpoint).

        return $next($request);
    }
}
