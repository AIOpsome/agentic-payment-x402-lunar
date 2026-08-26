<?php

return [
    'network' => env('LUNAR_CRYPTO_NETWORK', 'base'),
    'asset' => env('LUNAR_CRYPTO_ASSET', 'USDC'),
    'pay_to' => env('LUNAR_CRYPTO_PAY_TO'),

    // Facilitator verifies + settles the signed transfer. Coinbase is the
    // default (widest Base/USDC support); PayAI is the fallback if the
    // primary is unreachable or lacks coverage for a given network/asset.
    'facilitators' => [
        'coinbase' => [
            'url' => env('LUNAR_CRYPTO_COINBASE_FACILITATOR_URL', 'https://x402.org/facilitator'),
        ],
        'payai' => [
            'url' => env('LUNAR_CRYPTO_PAYAI_FACILITATOR_URL'),
        ],
    ],
    'facilitator_order' => ['coinbase', 'payai'],

    'x402' => [
        'network' => env('LUNAR_CRYPTO_X402_NETWORK', 'base'),
        'pay_to' => env('LUNAR_CRYPTO_X402_PAY_TO'),
    ],
];
