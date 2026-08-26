<?php

use Lunar\CryptoPayments\Actions\ValidatePaymentPayload;

function validRequirements(): array
{
    return [
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'amount' => '10000000',
        'asset' => '0x833589fCD6eDb6e08f4c7C32D4f71b54bdA02913',
        'payTo' => '0xmerchant',
        'maxTimeoutSeconds' => 60,
    ];
}

function validPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'x402Version' => 2,
        'accepted' => validRequirements(),
        'payload' => [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xpayer',
                'to' => '0xmerchant',
                'value' => '10000000',
                'validAfter' => '1740672089',
                'validBefore' => '1999999999',
                'nonce' => '0xnonce',
            ],
        ],
    ], $overrides);
}

it('accepts a well-formed payload matching requirements', function () {
    $error = (new ValidatePaymentPayload)->execute(validPayload(), validRequirements());

    expect($error)->toBeNull();
});

it('rejects a payload missing accepted', function () {
    $payload = validPayload();
    unset($payload['accepted']);

    $error = (new ValidatePaymentPayload)->execute($payload, validRequirements());

    expect($error)->toContain('accepted payment requirements');
});

it('rejects a payload whose accepted amount does not match requirements (quote drift)', function () {
    $payload = validPayload(['accepted' => ['amount' => '5000000']]);

    $error = (new ValidatePaymentPayload)->execute($payload, validRequirements());

    expect($error)->toContain('accepted.amount');
});

it('rejects a payload whose accepted network does not match requirements', function () {
    $payload = validPayload(['accepted' => ['network' => 'eip155:84532']]);

    $error = (new ValidatePaymentPayload)->execute($payload, validRequirements());

    expect($error)->toContain('accepted.network');
});

it('rejects a payload missing the authorization block', function () {
    $payload = validPayload();
    unset($payload['payload']['authorization']);

    $error = (new ValidatePaymentPayload)->execute($payload, validRequirements());

    expect($error)->toContain('missing its authorization');
});

it('rejects a zero or negative authorization value', function () {
    $error = (new ValidatePaymentPayload)->execute(
        validPayload(['payload' => ['authorization' => ['value' => '0']]]),
        validRequirements(),
    );

    expect($error)->toContain('positive integer');
});

it('rejects a payload missing its signature', function () {
    $payload = validPayload();
    unset($payload['payload']['signature']);

    $error = (new ValidatePaymentPayload)->execute($payload, validRequirements());

    expect($error)->toContain('missing its signature');
});

it('rejects an oversized payload', function () {
    $payload = validPayload(['payload' => ['authorization' => ['nonce' => str_repeat('a', 20 * 1024)]]]);

    $error = (new ValidatePaymentPayload)->execute($payload, validRequirements());

    expect($error)->toContain('too large');
});
