<?php

namespace App\Support;

use App\Models\GoalkeeperProfile;
use Carbon\CarbonImmutable;

class AvailabilityCalendar
{
    public const WINDOW_DAYS = 30;

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(config('marketplace.timezone'));
    }

    public static function dateRules(bool $future = true): array
    {
        return $future
            ? ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::today()->toDateString(), 'before_or_equal:'.self::maxDate()->toDateString()]
            : ['required', 'date_format:Y-m-d'];
    }

    public static function maxDate(): CarbonImmutable
    {
        return self::today()->addDays(self::WINDOW_DAYS - 1);
    }

    /**
     * Available dates in the next 30 days; with a city, only the dates the goalkeeper is in that city.
     *
     * @return list<string>
     */
    public static function upcomingDates(GoalkeeperProfile $profile, ?int $cityId = null): array
    {
        return array_keys(array_filter(self::upcomingCities($profile), fn ($city) => $cityId === null || $city === $cityId));
    }

    /**
     * Available dates in the next 30 days, each with the city the goalkeeper is in that day.
     *
     * @return array<string, ?int>
     */
    public static function upcomingCities(GoalkeeperProfile $profile): array
    {
        $first = self::today();
        [$schedule, $explicit, $unavailable, $extraCities] = self::availabilityIndex($profile);

        return collect(range(0, self::WINDOW_DAYS - 1))
            ->map(fn ($offset) => self::resolveDate($first->addDays($offset)->toDateString(), $schedule, $explicit, $unavailable, $extraCities)
                + ['iso' => $first->addDays($offset)->toDateString()])
            ->filter(fn (array $resolved) => $resolved['available'])
            ->mapWithKeys(fn (array $resolved) => [$resolved['iso'] => $resolved['city_id']])->all();
    }

    public static function week(GoalkeeperProfile $profile, ?string $start = null): array
    {
        $first = $start ? CarbonImmutable::parse($start, config('marketplace.timezone')) : self::today();
        [$schedule, $explicit, $unavailable, $extraCities] = self::availabilityIndex($profile);

        return self::calendarDays($first, 7, $schedule, $explicit, $unavailable, $extraCities, self::cityNames($profile));
    }

    public static function window(GoalkeeperProfile $profile): array
    {
        [$schedule, $explicit, $unavailable, $extraCities] = self::availabilityIndex($profile);

        return self::calendarDays(self::today(), self::WINDOW_DAYS, $schedule, $explicit, $unavailable, $extraCities, self::cityNames($profile));
    }

    /**
     * @param  array<int, array{start: string, end: string, city_id?: int|null}>  $schedule
     * @param  array<string, int|null>  $extraDateCities  extra date => city id (missing means the schedule has no city)
     * @return array{kind: string, available: bool, start: ?string, end: ?string, city_id: ?int}
     */
    public static function resolveDate(string $date, array $schedule, array $extraDates, array $offDates, array $extraDateCities = []): array
    {
        $day = CarbonImmutable::parse($date, config('marketplace.timezone'));
        $weekday = $day->dayOfWeekIso;
        $usual = $schedule[$weekday] ?? null;

        if ($usual) {
            $dayOff = in_array($date, $offDates, true);

            return ['kind' => $dayOff ? 'day_off' : 'usual', 'available' => ! $dayOff, 'start' => $dayOff ? null : $usual['start'], 'end' => $dayOff ? null : $usual['end'],
                'city_id' => $dayOff ? null : ($usual['city_id'] ?? null)];
        }

        $extra = in_array($date, $extraDates, true);

        return ['kind' => $extra ? 'extra' : 'off', 'available' => $extra, 'start' => $extra ? '18:00' : null, 'end' => $extra ? '23:00' : null,
            'city_id' => $extra ? ($extraDateCities[$date] ?? null) : null];
    }

    /** @param array<int, string> $cityNames */
    private static function calendarDays(CarbonImmutable $first, int $count, array $schedule, array $explicit, array $unavailable, array $extraCities, array $cityNames): array
    {
        return collect(range(0, $count - 1))->map(function ($offset) use ($first, $schedule, $explicit, $unavailable, $extraCities, $cityNames) {
            $day = $first->addDays($offset);
            $resolved = self::resolveDate($day->toDateString(), $schedule, $explicit, $unavailable, $extraCities);

            return [
                'iso' => $day->toDateString(), 'day' => mb_strtoupper($day->locale(app()->getLocale())->isoFormat('ddd')),
                'date' => $day->day, 'available' => $resolved['available'],
                'cityId' => $resolved['city_id'], 'cityName' => $resolved['city_id'] ? ($cityNames[$resolved['city_id']] ?? null) : null,
            ];
        })->all();
    }

    /** @return array<int, string> */
    private static function cityNames(GoalkeeperProfile $profile): array
    {
        return $profile->serviceCities()->mapWithKeys(fn ($city) => [(int) $city->id => $city->name])->all();
    }

    /**
     * Weekly days and extra dates without a city (older rows, or cities that were removed) count as the primary city.
     *
     * @return array{array<int, array{start: string, end: string, city_id: ?int}>, list<string>, list<string>, array<string, ?int>}
     */
    private static function availabilityIndex(GoalkeeperProfile $profile): array
    {
        $weeklyHours = $profile->relationLoaded('weeklyHours') ? $profile->getRelation('weeklyHours') : $profile->weeklyHours()->get();
        $dated = $profile->relationLoaded('upcomingAvailabilities') ? $profile->getRelation('upcomingAvailabilities') : $profile->upcomingAvailabilities()->get();
        $exceptions = $profile->relationLoaded('upcomingAvailabilityExceptions')
            ? $profile->getRelation('upcomingAvailabilityExceptions')
            : $profile->upcomingAvailabilityExceptions()->get();
        $cityIds = $profile->serviceCityIds();
        $primary = $profile->city_id ? (int) $profile->city_id : null;
        $cityFor = fn ($cityId) => $cityId && in_array((int) $cityId, $cityIds, true) ? (int) $cityId : $primary;

        return [
            $weeklyHours->mapWithKeys(fn ($hour) => [(int) $hour->weekday => [
                'start' => substr($hour->start_time, 0, 5), 'end' => substr($hour->end_time, 0, 5), 'city_id' => $cityFor($hour->city_id),
            ]])->all(),
            $dated->map(fn ($row) => $row->available_on->toDateString())->all(),
            $exceptions->map(fn ($row) => $row->unavailable_on->toDateString())->all(),
            $dated->mapWithKeys(fn ($row) => [$row->available_on->toDateString() => $cityFor($row->city_id)])->all(),
        ];
    }
}
