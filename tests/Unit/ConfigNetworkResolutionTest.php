<?php

use Lunar\CryptoPayments\Actions\ValidateCryptoConfig;

/**
 * Exercises the shipped config/crypto.php's env resolution directly, then
 * replays the two boot-time checks CryptoPaymentsServiceProvider::boot()
 * runs against it — the combination that broke app boot when a single global
 * `asset` default had to be valid for two independently-set networks.
 */
function cryptoConfigWithEnv(array $env): array
{
    foreach ($env as $key => $value) {
        $_SERVER[$key] = $value;
    }

    try {
        return require __DIR__.'/../../config/crypto.php';
    } finally {
        foreach (array_keys($env) as $key) {
            unset($_SERVER[$key]);
        }
    }
}

function assertBootValidationPasses(array $config): void
{
    $validate = new ValidateCryptoConfig;
    $validate->execute($config['network'], $config['asset']);
    $validate->execute($config['x402']['network'], $config['asset']);
}

it('leaves asset unset by default so per-network resolution applies', function () {
    $config = cryptoConfigWithEnv([]);

    expect($config['asset'])->toBeNull()
        ->and($config['network'])->toBe('eip155:8453')
        ->and($config['x402']['network'])->toBe('eip155:8453');

    assertBootValidationPasses($config);
});

it('switches both networks to Sepolia from LUNAR_CRYPTO_NETWORK alone, without a boot failure', function () {
    $config = cryptoConfigWithEnv(['LUNAR_CRYPTO_NETWORK' => 'eip155:84532']);

    expect($config['network'])->toBe('eip155:84532')
        ->and($config['x402']['network'])->toBe('eip155:84532')
        ->and($config['asset'])->toBeNull();

    assertBootValidationPasses($config);
});

it('switches only the x402 network from LUNAR_CRYPTO_X402_NETWORK, without a boot failure', function () {
    $config = cryptoConfigWithEnv(['LUNAR_CRYPTO_X402_NETWORK' => 'eip155:84532']);

    expect($config['x402']['network'])->toBe('eip155:84532')
        ->and($config['network'])->toBe('eip155:8453')
        ->and($config['asset'])->toBeNull();

    assertBootValidationPasses($config);
});
