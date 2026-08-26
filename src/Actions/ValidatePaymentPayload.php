<?php

namespace Lunar\CryptoPayments\Actions;

/**
 * Shape/adversarial-input validation on a signed x402 PaymentPayload before
 * it's ever forwarded to a facilitator. Fails closed on anything malformed,
 * oversized, or that doesn't exactly match the requirements we computed
 * server-side — including the case where the payload's `accepted.amount`
 * reflects an order total that has since changed (quote-to-settlement
 * drift): a stale signed amount will never match a freshly recomputed one.
 */
class ValidatePaymentPayload
{
    protected const MAX_BYTES = 16 * 1024;

    /**
     * @return string|null  An error message, or null if the payload is valid.
     */
    public function execute(array $payload, array $requirements): ?string
    {
        if (strlen(json_encode($payload) ?: '') > self::MAX_BYTES) {
            return 'Payment payload is too large.';
        }

        $accepted = $payload['accepted'] ?? null;

        if (! is_array($accepted)) {
            return 'Payment payload is missing the accepted payment requirements.';
        }

        foreach (['scheme', 'network', 'asset', 'payTo', 'amount'] as $key) {
            if (! array_key_exists($key, $accepted) || (string) $accepted[$key] !== (string) $requirements[$key]) {
                return "Payment payload's accepted.{$key} does not match what was required.";
            }
        }

        $authorization = $payload['payload']['authorization'] ?? null;

        if (! is_array($authorization)) {
            return 'Payment payload is missing its authorization.';
        }

        foreach (['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'] as $key) {
            if (! isset($authorization[$key]) || $authorization[$key] === '') {
                return "Payment payload's authorization is missing {$key}.";
            }
        }

        if (! ctype_digit((string) $authorization['value']) || (int) $authorization['value'] <= 0) {
            return 'Payment payload authorization value must be a positive integer.';
        }

        if (empty($payload['payload']['signature']) || ! is_string($payload['payload']['signature'])) {
            return 'Payment payload is missing its signature.';
        }

        return null;
    }
}
