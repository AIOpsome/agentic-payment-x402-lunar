<?php

namespace Lunar\CryptoPayments\Actions;

/**
 * Cross-checks the configured asset address against the configured
 * network, catching the most common install-time misconfiguration: a
 * testnet asset address paired with a mainnet network, or vice versa. Pure
 * lookup — no Eloquent, no DB — run at service-provider boot so a bad
 * config fails loudly at deploy time, not silently at the first checkout.
 *
 * Unrecognized networks are allowed through unchecked (no known-asset list
 * to validate against) — this guards a known mistake, it doesn't
 * allowlist networks.
 */
class ValidateCryptoConfig
{
    /**
     * CAIP-2 network id => known-good USDC contract addresses.
     */
    protected const KNOWN_USDC_ADDRESSES = [
        'eip155:8453' => '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913', // Base mainnet
        'eip155:84532' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e', // Base Sepolia
    ];

    /**
     * @throws \RuntimeException  if the configured asset doesn't match a known-good address for the network.
     */
    public function execute(string $network, string $asset): void
    {
        $known = self::KNOWN_USDC_ADDRESSES[$network] ?? null;

        if ($known === null) {
            return;
        }

        if (strcasecmp($known, $asset) !== 0) {
            throw new \RuntimeException(
                "Configured lunar-crypto.asset [{$asset}] does not match the known USDC address for network [{$network}]. ".
                'This usually means a testnet/mainnet mismatch between lunar-crypto.network and lunar-crypto.asset.'
            );
        }
    }
}
