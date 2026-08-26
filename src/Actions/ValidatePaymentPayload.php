<?php

namespace Lunar\CryptoPayments\Actions;

/**
 * Shape/adversarial-input validation on a signed x402 PaymentPayload before
 * it's ever forwarded to a facilitator. Fails closed on anything malformed,
 * oversized, or that doesn't exactly match the requirements we computed
 * server-side.
 *
 * Critically, this checks the SIGNED `payload.authorization.value`/`.to`
 * against requirements, not just the unsigned, client-echoed
 * `accepted.amount`/`.payTo` — a client can rewrite `accepted.*` to
 * anything and still pass a check that only compares `accepted.*` against
 * itself. It's the authorization fields the wallet actually signed (EIP-712)
 * that a forged accepted block can't fake. This also closes
 * quote-to-settlement drift: a stale signed value from before an order
 * total changed won't match a freshly recomputed requirement.
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
            $value = $accepted[$key] ?? null;

            if (! is_scalar($value) || (string) $value !== (string) $requirements[$key]) {
                return "Payment payload's accepted.{$key} does not match what was required.";
            }
        }

        $authorization = $payload['payload']['authorization'] ?? null;

        if (! is_array($authorization)) {
            return 'Payment payload is missing its authorization.';
        }

        foreach (['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'] as $key) {
            if (! isset($authorization[$key]) || $authorization[$key] === '' || ! is_scalar($authorization[$key])) {
                return "Payment payload's authorization is missing {$key}.";
            }
        }

        if (! ctype_digit((string) $authorization['value']) || (int) $authorization['value'] <= 0) {
            return 'Payment payload authorization value must be a positive integer.';
        }

        // The part that actually matters: what the wallet signed. accepted.*
        // above is just the client's unsigned claim about what it's about
        // to pay — checking it against itself proves nothing on its own.
        if ((string) $authorization['value'] !== (string) $requirements['amount']) {
            return 'Payment payload authorization value does not match the required amount.';
        }

        if (strcasecmp((string) $authorization['to'], (string) $requirements['payTo']) !== 0) {
            return 'Payment payload authorization recipient does not match the required payee.';
        }

        if (empty($payload['payload']['signature']) || ! is_string($payload['payload']['signature'])) {
            return 'Payment payload is missing its signature.';
        }

        return null;
    }
}
