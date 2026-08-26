<?php

return [
    // CAIP-2 network id. Base mainnet: eip155:8453. Base Sepolia (testnet): eip155:84532.
    'network' => env('LUNAR_CRYPTO_NETWORK', 'eip155:8453'),

    // ERC-20 token contract address (not a symbol) — USDC on the network above.
    'asset' => env('LUNAR_CRYPTO_ASSET', '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913'),

    'pay_to' => env('LUNAR_CRYPTO_PAY_TO'),

    // Facilitators verify + settle the signed EIP-3009 transfer (x402 protocol
    // v2). Tried in `facilitator_order`; a facilitator that errors or times
    // out is skipped in favour of the next one.
    'facilitators' => [
        // Default: PayAI's public facilitator. No auth required, supports
        // Base mainnet (eip155:8453) directly.
        'payai' => [
            'url' => env('LUNAR_CRYPTO_PAYAI_FACILITATOR_URL', 'https://facilitator.payai.network'),
        ],

        // Coinbase's free public facilitator. No auth required, but only
        // supports Base Sepolia (testnet) for the EVM `exact` scheme —
        // verified live against x402.org/facilitator/supported. Useful for
        // local/dev testing, not a mainnet option.
        'coinbase_testnet' => [
            'url' => env('LUNAR_CRYPTO_COINBASE_TESTNET_FACILITATOR_URL', 'https://x402.org/facilitator'),
        ],

        // Coinbase's CDP-hosted facilitator. Supports Base mainnet, but
        // requires a CDP API key ID + secret (authenticated requests, not a
        // bare URL) and is metered past 1,000 free tx/month. Opt-in: only
        // used if both credentials are set. Auth signing is not yet
        // implemented in SettleOnChainPayment — see README.
        'coinbase_cdp' => [
            'url' => env('LUNAR_CRYPTO_COINBASE_CDP_FACILITATOR_URL', 'https://api.cdp.coinbase.com/platform/v2/x402'),
            'api_key_id' => env('LUNAR_CRYPTO_CDP_API_KEY_ID'),
            'api_key_secret' => env('LUNAR_CRYPTO_CDP_API_KEY_SECRET'),
        ],
    ],

    // Production default: PayAI only. Add 'coinbase_cdp' here once CDP auth
    // signing is implemented and credentials are configured. Don't add
    // 'coinbase_testnet' here — it can't settle mainnet payments.
    'facilitator_order' => ['payai'],

    'x402' => [
        'network' => env('LUNAR_CRYPTO_X402_NETWORK', 'eip155:8453'),
        'pay_to' => env('LUNAR_CRYPTO_X402_PAY_TO'),
    ],
];
