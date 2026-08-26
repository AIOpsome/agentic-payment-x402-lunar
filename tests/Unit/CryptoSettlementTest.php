<?php

use Lunar\CryptoPayments\Models\CryptoSettlement;

it('is recorded only once the order has been finalized', function () {
    expect((new CryptoSettlement(['status' => 'settled']))->isRecorded())->toBeFalse()
        ->and((new CryptoSettlement(['status' => 'recorded']))->isRecorded())->toBeTrue();
});
