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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GoalkeeperProfile extends Model
{
    /** Besides the primary city: at most five cities per profile. */
    public const MAX_ADDITIONAL_CITIES = 4;

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

    /** Cities the goalkeeper also plays in, besides the primary city_id (same country only). */
    public function additionalCities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'goalkeeper_profile_cities')->withTimestamps()->orderBy('cities.name');
    }

    /**
     * Primary city first, then the additional cities.
     *
     * @return Collection<int, City>
     */
    public function serviceCities(): Collection
    {
        $additional = $this->relationLoaded('additionalCities') ? $this->getRelation('additionalCities') : $this->additionalCities()->get();

        return collect([$this->city])->filter()->concat($additional->where('id', '!=', $this->city_id))->values();
    }

    /** @return list<int> */
    public function serviceCityIds(): array
    {
        return $this->serviceCities()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Replace the additional cities, then move weekly days and extra dates whose city is no longer offered
     * back to the primary city.
     *
     * @param  list<int|string>  $cityIds
     */
    public function syncAdditionalCities(array $cityIds): void
    {
        $ids = collect($cityIds)->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === (int) $this->city_id)->unique()->values();
        $this->additionalCities()->sync($ids->all());
        $this->unsetRelation('additionalCities');
        $this->normalizeScheduleCities();
    }

    /** Weekly days and extra dates always point at one of the profile's cities; anything else becomes the primary city. */
    public function normalizeScheduleCities(): void
    {
        $offered = [(int) $this->city_id, ...$this->additionalCities()->pluck('cities.id')->map(fn ($id) => (int) $id)->all()];
        foreach ([GoalkeeperWeeklyHour::class, GoalkeeperAvailability::class] as $model) {
            $model::where('goalkeeper_profile_id', $this->id)
                ->where(fn ($rows) => $rows->whereNull('city_id')->orWhereNotIn('city_id', $offered))
                ->update(['city_id' => $this->city_id]);
        }
    }

    /** Profiles whose primary or additional cities include the given city. */
    public function scopeServingCity(Builder $query, int $cityId): Builder
    {
        return $query->where(fn (Builder $profiles) => $profiles->where('goalkeeper_profiles.city_id', $cityId)
            ->orWhereHas('additionalCities', fn (Builder $cities) => $cities->whereKey($cityId)));
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
