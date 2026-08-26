<?php

namespace Lunar\CryptoPayments\DataTransferObjects;

class SettlementResult
{
    public function __construct(
        public bool $success,
        public ?string $txHash = null,
        public ?int $settledAmount = null,
        public ?string $message = null,
    ) {}
}
