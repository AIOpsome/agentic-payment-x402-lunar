<?php

namespace Lunar\CryptoPayments\Actions;

/**
 * Rescales an order total from the store currency's minor unit (e.g. cents,
 * 2 decimals) to the configured crypto asset's atomic unit (e.g. USDC, 6
 * decimals). Pure integer math — no Eloquent, no DB — so this is directly
 * unit testable.
 *
 * Assumes the store currency is 1:1 with the asset (e.g. USD with USDC).
 * FX conversion between a non-pegged store currency and the asset is out of
 * scope here — refuses rather than silently mispricing.
 */
class ConvertToAssetUnits
{
    public function execute(int $value, int $currencyDecimals, int $assetDecimals, string $currencyCode, string $peggedCurrency): string
    {
        if (strcasecmp($currencyCode, $peggedCurrency) !== 0) {
            throw new \RuntimeException(
                "Cannot settle a crypto payment for an order in {$currencyCode}: no FX conversion to {$peggedCurrency} is implemented. ".
                'Configure lunar-crypto.pegged_currency to match the store currency, or add FX conversion before using this driver.'
            );
        }

        $diff = $assetDecimals - $currencyDecimals;

        $amount = $diff >= 0
            ? $value * (10 ** $diff)
            : intdiv($value, 10 ** abs($diff));

        return (string) $amount;
    }
}
