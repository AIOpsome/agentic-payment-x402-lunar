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
    public const DEFAULT_ASSETS = [
        'eip155:8453' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'eip155:84532' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
    ];

    public function __construct(
        protected ConvertToAssetUnits $convert,
        protected ResolvePayeeAddress $resolvePayee,
    ) {}

    public function execute(Price $total, array $config = [], string $payeeKey = 'pay_to'): array
    {
        $amount = $this->convert->execute(
            value: $total->value,
            currencyDecimals: (int) $total->currency->decimal_places,
            assetDecimals: (int) ($config['asset_decimals'] ?? config('lunar-crypto.asset_decimals', 6)),
            currencyCode: $total->currency->code,
            peggedCurrency: $config['pegged_currency'] ?? config('lunar-crypto.pegged_currency', 'USD'),
        );

        $network = $config['network'] ?? config('lunar-crypto.network', 'eip155:8453');
        $asset = $config['asset']
            ?? config('lunar-crypto.asset')
            ?? self::DEFAULT_ASSETS[$network]
            ?? self::DEFAULT_ASSETS['eip155:8453'];

        return [
            'scheme' => 'exact',
            'network' => $network,
            'amount' => $amount,
            'asset' => $asset,
            'payTo' => $this->resolvePayee->execute($payeeKey, $config),
            'maxTimeoutSeconds' => 60,
        ];
    }
}
