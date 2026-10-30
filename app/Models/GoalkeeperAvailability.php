<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalkeeperAvailability extends Model
{
    protected $fillable = ['available_on'];

    protected function casts(): array
    {
        return ['available_on' => 'immutable_date:Y-m-d'];
    }

    public function goalkeeperProfile(): BelongsTo
    {
        return $this->belongsTo(GoalkeeperProfile::class);
    }
}
