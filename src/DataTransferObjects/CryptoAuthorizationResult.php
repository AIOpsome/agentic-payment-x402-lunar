<?php

namespace Lunar\CryptoPayments\DataTransferObjects;

use Lunar\Models\Contracts\Order;

class CryptoAuthorizationResult
{
    public function __construct(
        public bool $success,
        public ?Order $order = null,
        public ?string $message = null,
        public ?string $transaction = null,
        public ?string $network = null,
    ) {}
}
