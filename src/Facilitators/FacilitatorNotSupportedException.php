<?php

namespace Lunar\CryptoPayments\Facilitators;

class FacilitatorNotSupportedException extends \RuntimeException
{
    public static function authNotImplemented(string $facilitator): self
    {
        return new self("Facilitator [{$facilitator}] requires authenticated requests, which are not yet implemented.");
    }
}
