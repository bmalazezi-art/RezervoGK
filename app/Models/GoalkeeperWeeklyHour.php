<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalkeeperWeeklyHour extends Model
{
    protected $fillable = ['weekday', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function goalkeeperProfile(): BelongsTo
    {
        return $this->belongsTo(GoalkeeperProfile::class);
    }
}
