<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdminReportAudit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(GoalkeeperProfileReport::class, 'goalkeeper_profile_report_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Admin report audit entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Admin report audit entries are append-only.'));
    }
}
