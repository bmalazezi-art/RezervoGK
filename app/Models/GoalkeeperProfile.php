<?php

namespace App\Models;

use App\Support\AvailabilityCalendar;
use App\Support\GoalkeeperEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class GoalkeeperProfile extends Model
{
    protected $fillable = [
        'city_id', 'slug', 'name', 'bio', 'photo_url', 'price_cents', 'currency',
        'experience_years', 'languages', 'rating', 'review_count', 'matches_played',
        'availability_label', 'is_verified', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean', 'is_suspended' => 'boolean',
            'admin_hidden' => 'boolean',
            'user_id' => 'integer', 'price_cents' => 'integer', 'experience_years' => 'integer',
            'languages' => 'array', 'rating' => 'float', 'review_count' => 'integer',
            'matches_played' => 'integer', 'is_verified' => 'boolean', 'is_featured' => 'boolean',
        ];
    }

    /** Readable public link name, e.g. "bajram-malazezi", then "-2", "-3" on clashes. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'portier';
        $base = Str::limit($base, 80, '');
        $slug = $base;
        for ($n = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    public function publicPath(): string
    {
        return '/p/'.$this->slug;
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return GoalkeeperEligibility::publicProfiles($query);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function weeklyHours(): HasMany
    {
        return $this->hasMany(GoalkeeperWeeklyHour::class)->orderBy('weekday');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(GoalkeeperAvailability::class)->orderBy('available_on');
    }

    public function upcomingAvailabilities(): HasMany
    {
        return $this->availabilities()->where('available_on', '>=', AvailabilityCalendar::today()->toDateString());
    }

    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(GoalkeeperAvailabilityException::class, 'goalkeeper_profile_id')->orderBy('unavailable_on');
    }

    public function upcomingAvailabilityExceptions(): HasMany
    {
        return $this->availabilityExceptions()->where('unavailable_on', '>=', AvailabilityCalendar::today()->toDateString());
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function contact(): HasOne
    {
        return $this->hasOne(GoalkeeperContact::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(GoalkeeperProfileReport::class);
    }

    public function adminListingAudits(): HasMany
    {
        return $this->hasMany(AdminListingAudit::class);
    }

    public function matchFormats(): BelongsToMany
    {
        return $this->belongsToMany(MatchFormat::class)->inPreferredOrder();
    }
}
