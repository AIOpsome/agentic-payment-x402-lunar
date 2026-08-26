<?php

namespace Lunar\CryptoPayments\Models;

use Lunar\Base\BaseModel;

/**
 * The currently-confirmed payee address for a given config key ('pay_to' or
 * 'x402_pay_to'). GuardPayeeAddressChange compares the live config value
 * against this row on every authorize attempt, so a changed .env/deploy
 * pipeline value is caught (and blocked) instead of silently redirecting
 * future settlements.
 */
class CryptoPayeeConfig extends BaseModel
{
    protected $guarded = [];
}
