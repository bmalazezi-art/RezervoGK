<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEmailDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'next_attempt_at' => 'immutable_datetime',
            'reserved_until' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ManualPayment::class, 'manual_payment_id');
    }
}
