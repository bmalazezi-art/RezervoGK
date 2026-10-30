<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ManualPayment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['receipt_path', 'bank_transaction_reference'];

    protected function casts(): array
    {
        return ['bank_snapshot' => 'array', 'amount_cents' => 'integer', 'duration_seconds' => 'integer',
            'paid_on' => 'immutable_date', 'received_on' => 'immutable_date',
            'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(PaymentAudit::class);
    }
}
