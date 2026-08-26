<?php

namespace Lunar\CryptoPayments\Actions;

use Lunar\CryptoPayments\DataTransferObjects\SettlementResult;

/**
 * Shared settlement core used by both entry points:
 * - CryptoPaymentType::authorize() for human wallet checkout
 * - X402PaymentMiddleware for agent-initiated requests
 *
 * Verifies a signed on-chain transfer (EIP-3009 transferWithAuthorization,
 * e.g. USDC on Base) against a facilitator, then settles it. Tries
 * facilitators in `lunar-crypto.facilitator_order` (Coinbase first, PayAI as
 * fallback) so a single facilitator outage doesn't take checkout down.
 */
class SettleOnChainPayment
{
    public function execute(array $paymentPayload, int $expectedAmount, string $expectedAsset): SettlementResult
    {
        // For each facilitator in config('lunar-crypto.facilitator_order'):
        // 1. Verify payload shape (signature, nonce, expiry, recipient, amount, asset).
        // 2. POST facilitator /verify.
        // 3. POST facilitator /settle to broadcast + confirm the transfer.
        // 4. On facilitator error/timeout, fall through to the next one.
        // Return the on-chain tx hash + settled amount for the caller to record.

        throw new \RuntimeException('Not yet implemented — spike stub.');
    }
}
