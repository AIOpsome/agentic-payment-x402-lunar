<?php

namespace Lunar\CryptoPayments\Actions;

use Illuminate\Support\Facades\Log;
use Lunar\CryptoPayments\Exceptions\PayeeAddressChangedException;
use Lunar\CryptoPayments\Models\CryptoPayeeConfig;

/**
 * Guards against a compromised (or mistyped) .env/deploy pipeline silently
 * redirecting settlements to a different wallet. Unlike the asset/network
 * check (ValidateCryptoConfig, pure and boot-time), this needs a persistent
 * record of the last-confirmed address, so it runs per authorize attempt
 * rather than at boot — mirrors agentic-pay-woocommerce's
 * PayeeAddressChangeGuard, adapted for a package with no settings UI: a
 * changed pay_to fails closed until an operator re-confirms it via
 * `lunar-crypto:confirm-payee`, rather than just logging and proceeding.
 */
class GuardPayeeAddressChange
{
    /**
     * @throws \Lunar\CryptoPayments\Exceptions\PayeeAddressChangedException  if the configured address changed without confirmation.
     */
    public function execute(string $key, ?string $configuredAddress): void
    {
        if ($configuredAddress === null || $configuredAddress === '') {
            return;
        }

        $record = CryptoPayeeConfig::where('key', $key)->first();

        // First time this key has ever been seen — nothing to protect yet.
        // Record it as the confirmed baseline rather than blocking a fresh
        // install on a check with nothing to compare against.
        if (! $record) {
            CryptoPayeeConfig::create(['key' => $key, 'address' => $configuredAddress]);

            return;
        }

        if (strcasecmp($record->address, $configuredAddress) === 0) {
            return;
        }

        Log::critical(
            "lunar-crypto: payee address for [{$key}] changed from [{$record->address}] to [{$configuredAddress}] ".
            'without confirmation — settlement blocked.',
            ['key' => $key, 'previous' => $record->address, 'configured' => $configuredAddress]
        );

        throw PayeeAddressChangedException::unconfirmed($key, $record->address, $configuredAddress);
    }
}
