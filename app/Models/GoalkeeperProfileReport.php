<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoalkeeperProfileReport extends Model
{
    protected $guarded = ['id', 'goalkeeper_profile_id'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(GoalkeeperProfile::class, 'goalkeeper_profile_id');
    }

    public function adminAudits(): HasMany
    {
        return $this->hasMany(AdminReportAudit::class, 'goalkeeper_profile_report_id');
    }
}
