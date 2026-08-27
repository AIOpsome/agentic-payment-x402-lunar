<?php

namespace Lunar\CryptoPayments\Actions;

/**
 * The single source of truth for "what payee address applies to this key
 * right now" — used by GuardPayeeAddressChange's callers (AuthorizeCryptoPayment,
 * BuildPaymentRequirements) and by ConfirmPayeeAddress, so the confirmed
 * baseline and the guarded value can never diverge. 'x402_pay_to' falls back
 * from a per-request/x402 config override, to lunar-crypto.x402.pay_to, to
 * the global lunar-crypto.pay_to — the same fallback chain BuildPaymentRequirements
 * always applied for x402 requests, just centralized here.
 */
class ResolvePayeeAddress
{
    public function execute(string $key, array $config = []): ?string
    {
        return $config['pay_to']
            ?? ($key === 'x402_pay_to' ? config('lunar-crypto.x402.pay_to') : null)
            ?? config('lunar-crypto.pay_to');
    }
}
