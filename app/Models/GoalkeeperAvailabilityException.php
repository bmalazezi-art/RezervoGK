<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalkeeperAvailabilityException extends Model
{
    protected $fillable = ['unavailable_on'];

    protected function casts(): array
    {
        return ['unavailable_on' => 'immutable_date:Y-m-d'];
    }

    public function goalkeeperProfile(): BelongsTo
    {
        return $this->belongsTo(GoalkeeperProfile::class);
    }
}
