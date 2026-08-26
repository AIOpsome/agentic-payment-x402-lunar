<?php

namespace Lunar\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Base\BaseModel;
use Lunar\Models\Cart;
use Lunar\Models\Order;

/**
 * Audit trail of on-chain settlements, written the instant a facilitator
 * confirms one — before the (fallible) order write that follows it. If that
 * write fails, this row is the record that funds already moved, and a
 * retried authorize() finds it instead of settling (and charging) again.
 */
class CryptoSettlement extends BaseModel
{
    protected $guarded = [];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::modelClass(), 'cart_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::modelClass(), 'order_id');
    }

    public function isRecorded(): bool
    {
        return $this->status === 'recorded';
    }
}
