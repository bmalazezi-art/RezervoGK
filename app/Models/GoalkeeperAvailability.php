<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalkeeperAvailability extends Model
{
    protected $fillable = ['available_on', 'city_id'];

    protected function casts(): array
    {
        return ['available_on' => 'immutable_date:Y-m-d', 'city_id' => 'integer'];
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
