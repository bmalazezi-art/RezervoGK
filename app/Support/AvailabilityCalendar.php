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

    /** @return list<string> */
    public static function upcomingDates(GoalkeeperProfile $profile): array
    {
        $first = self::today();
        [$schedule, $explicit, $unavailable] = self::availabilityIndex($profile);

        return collect(range(0, self::WINDOW_DAYS - 1))
            ->map(fn ($offset) => $first->addDays($offset))
            ->filter(fn (CarbonImmutable $day) => self::resolveDate($day->toDateString(), $schedule, $explicit, $unavailable)['available'])
            ->map(fn (CarbonImmutable $day) => $day->toDateString())->values()->all();
    }

    public static function week(GoalkeeperProfile $profile, ?string $start = null): array
    {
        $first = $start ? CarbonImmutable::parse($start, config('marketplace.timezone')) : self::today();
        [$schedule, $explicit, $unavailable] = self::availabilityIndex($profile);

        return self::calendarDays($first, 7, $schedule, $explicit, $unavailable);
    }

    public static function window(GoalkeeperProfile $profile): array
    {
        [$schedule, $explicit, $unavailable] = self::availabilityIndex($profile);

        return self::calendarDays(self::today(), self::WINDOW_DAYS, $schedule, $explicit, $unavailable);
    }

    /** @param array<int, array{start: string, end: string}> $schedule */
    public static function resolveDate(string $date, array $schedule, array $extraDates, array $offDates): array
    {
        $day = CarbonImmutable::parse($date, config('marketplace.timezone'));
        $weekday = $day->dayOfWeekIso;
        $usual = $schedule[$weekday] ?? null;

        if ($usual) {
            $dayOff = in_array($date, $offDates, true);

            return ['kind' => $dayOff ? 'day_off' : 'usual', 'available' => ! $dayOff, 'start' => $dayOff ? null : $usual['start'], 'end' => $dayOff ? null : $usual['end']];
        }

        $extra = in_array($date, $extraDates, true);

        return ['kind' => $extra ? 'extra' : 'off', 'available' => $extra, 'start' => $extra ? '18:00' : null, 'end' => $extra ? '23:00' : null];
    }

    private static function calendarDays(CarbonImmutable $first, int $count, array $schedule, array $explicit, array $unavailable): array
    {
        return collect(range(0, $count - 1))->map(function ($offset) use ($first, $schedule, $explicit, $unavailable) {
            $day = $first->addDays($offset);
            return [
                'iso' => $day->toDateString(), 'day' => mb_strtoupper($day->locale(app()->getLocale())->isoFormat('ddd')),
                'date' => $day->day, 'available' => self::resolveDate($day->toDateString(), $schedule, $explicit, $unavailable)['available'],
            ];
        })->all();
    }

    /** @return array{array<int, array{start: string, end: string}>, list<string>, list<string>} */
    private static function availabilityIndex(GoalkeeperProfile $profile): array
    {
        $weeklyHours = $profile->relationLoaded('weeklyHours') ? $profile->getRelation('weeklyHours') : $profile->weeklyHours()->get();
        $dated = $profile->relationLoaded('upcomingAvailabilities') ? $profile->getRelation('upcomingAvailabilities') : $profile->upcomingAvailabilities()->get();
        $exceptions = $profile->relationLoaded('upcomingAvailabilityExceptions')
            ? $profile->getRelation('upcomingAvailabilityExceptions')
            : $profile->upcomingAvailabilityExceptions()->get();

        return [
            $weeklyHours->mapWithKeys(fn ($hour) => [(int) $hour->weekday => ['start' => substr($hour->start_time, 0, 5), 'end' => substr($hour->end_time, 0, 5)]])->all(),
            $dated->map(fn ($row) => $row->available_on->toDateString())->all(),
            $exceptions->map(fn ($row) => $row->unavailable_on->toDateString())->all(),
        ];
    }
}
