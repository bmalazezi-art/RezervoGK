<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalkeeperWeeklyHour extends Model
{
    protected $fillable = ['weekday', 'start_time', 'end_time', 'city_id'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'city_id' => 'integer'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function goalkeeperProfile(): BelongsTo
    {
        return $this->belongsTo(GoalkeeperProfile::class);
    }
}
