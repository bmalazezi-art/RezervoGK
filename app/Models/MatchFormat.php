<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MatchFormat extends Model
{
    protected $fillable = ['code'];

    public function scopeInPreferredOrder($query)
    {
        return $query->orderByRaw("CASE code WHEN '5v5' THEN 1 WHEN '6v6' THEN 2 WHEN '7v7' THEN 3 WHEN '9v9' THEN 4 WHEN '11v11' THEN 5 ELSE 6 END")
            ->orderBy('code');
    }

    public function goalkeeperProfiles(): BelongsToMany
    {
        return $this->belongsToMany(GoalkeeperProfile::class);
    }
}
