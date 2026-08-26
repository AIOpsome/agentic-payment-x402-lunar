<?php

namespace Lunar\CryptoPayments\Actions;

use Lunar\DataTypes\Price;

/**
 * Builds an x402 v2 PaymentRequirements object from any Lunar Price (a
 * cart's or an order's `->total`) — shared by CryptoPaymentType (human
 * checkout) and X402PaymentMiddleware (agent checkout) so both price a
 * payment the same way, off the same currency-to-asset conversion.
 */
class BuildPaymentRequirements
{
    public function __construct(protected ConvertToAssetUnits $convert) {}

    public function execute(Price $total, array $config = []): array
    {
        $amount = $this->convert->execute(
            value: $total->value,
            currencyDecimals: (int) $total->currency->decimal_places,
            assetDecimals: (int) ($config['asset_decimals'] ?? config('lunar-crypto.asset_decimals', 6)),
            currencyCode: $total->currency->code,
            peggedCurrency: $config['pegged_currency'] ?? config('lunar-crypto.pegged_currency', 'USD'),
        );

        return [
            'scheme' => 'exact',
            'network' => $config['network'] ?? config('lunar-crypto.network'),
            'amount' => $amount,
            'asset' => $config['asset'] ?? config('lunar-crypto.asset'),
            'payTo' => $config['pay_to'] ?? config('lunar-crypto.pay_to'),
            'maxTimeoutSeconds' => 60,
        ];
    }
}
