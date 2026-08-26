<?php

namespace Lunar\CryptoPayments\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lunar\CryptoPayments\DataTransferObjects\SettlementResult;
use Lunar\CryptoPayments\Facilitators\FacilitatorNotSupportedException;

/**
 * Shared settlement core used by both entry points:
 * - CryptoPaymentType::authorize() for human wallet checkout
 * - X402PaymentMiddleware for agent-initiated requests
 *
 * Verifies a signed EIP-3009 transfer (x402 protocol v2) against a
 * facilitator's /verify + /settle endpoints. Tries facilitators in
 * `lunar-crypto.facilitator_order` (PayAI first by default) so a single
 * facilitator outage doesn't take checkout down.
 */
class SettleOnChainPayment
{
    /**
     * @param  array  $paymentPayload  x402 v2 PaymentPayload (includes the chosen `accepted` PaymentRequirements)
     * @param  array  $paymentRequirements  x402 v2 PaymentRequirements the payload must match
     */
    public function execute(array $paymentPayload, array $paymentRequirements): SettlementResult
    {
        $order = config('lunar-crypto.facilitator_order', []);

        $lastFailure = null;

        foreach ($order as $name) {
            $facilitator = config("lunar-crypto.facilitators.{$name}");

            if (! $facilitator) {
                continue;
            }

            try {
                $result = $this->attempt($name, $facilitator, $paymentPayload, $paymentRequirements);

                if ($result->success) {
                    return $result;
                }

                $lastFailure = $result;
            } catch (FacilitatorNotSupportedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning("Crypto payment facilitator [{$name}] failed, trying next.", ['exception' => $e->getMessage()]);
            }
        }

        return $lastFailure ?? new SettlementResult(success: false, message: 'No facilitator was able to settle the payment.');
    }

    protected function attempt(string $name, array $facilitator, array $paymentPayload, array $paymentRequirements): SettlementResult
    {
        if (! empty($facilitator['api_key_secret'])) {
            throw FacilitatorNotSupportedException::authNotImplemented($name);
        }

        $body = [
            'x402Version' => 2,
            'paymentPayload' => $paymentPayload,
            'paymentRequirements' => $paymentRequirements,
        ];

        $verify = Http::baseUrl($facilitator['url'])
            ->timeout(10)
            ->post('/verify', $body)
            ->throw()
            ->json();

        if (! ($verify['isValid'] ?? false)) {
            return new SettlementResult(success: false, message: $verify['invalidReason'] ?? 'Payment verification failed');
        }

        $settle = Http::baseUrl($facilitator['url'])
            ->timeout(15)
            ->post('/settle', $body)
            ->throw()
            ->json();

        if (! ($settle['success'] ?? false)) {
            return new SettlementResult(success: false, message: $settle['errorReason'] ?? 'Settlement failed');
        }

        // Don't trust the facilitator's own numbers blindly — if it reports
        // settling a different amount/network than we asked for (facilitator
        // bug, or a compromised/MITM'd endpoint), that's not a payment we
        // should treat as valid just because `success` was true.
        $settledAmount = isset($settle['amount']) ? (string) $settle['amount'] : $paymentRequirements['amount'];

        if ($settledAmount !== $paymentRequirements['amount']) {
            Log::error("Crypto payment facilitator [{$name}] settled a different amount than requested — refusing to trust it.", [
                'requested' => $paymentRequirements['amount'],
                'settled' => $settledAmount,
                'transaction' => $settle['transaction'] ?? null,
            ]);

            return new SettlementResult(success: false, message: 'Facilitator settled a different amount than requested.');
        }

        if (isset($settle['network']) && $settle['network'] !== $paymentRequirements['network']) {
            Log::error("Crypto payment facilitator [{$name}] settled on a different network than requested — refusing to trust it.", [
                'requested' => $paymentRequirements['network'],
                'settled' => $settle['network'],
                'transaction' => $settle['transaction'] ?? null,
            ]);

            return new SettlementResult(success: false, message: 'Facilitator settled on a different network than requested.');
        }

        return new SettlementResult(
            success: true,
            txHash: $settle['transaction'] ?? null,
            settledAmount: (int) $settledAmount,
            message: null,
            payer: $settle['payer'] ?? null,
            facilitator: $name,
        );
    }
}
