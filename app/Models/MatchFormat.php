<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MatchFormat extends Model
{
    protected $fillable = ['code'];

    public function goalkeeperProfiles(): BelongsToMany
    {
        return $this->belongsToMany(GoalkeeperProfile::class);
    }
}
