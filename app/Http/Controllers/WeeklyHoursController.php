<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWeeklyHoursRequest;
use App\Models\GoalkeeperProfile;
use App\Support\AvailabilityCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WeeklyHoursController extends Controller
{
    public function update(UpdateWeeklyHoursRequest $request, GoalkeeperProfile $keeper): RedirectResponse
    {
        $data = $request->validated();
        $days = $data['days'];
        $syncExceptions = array_key_exists('offDates', $data) || array_key_exists('extraDates', $data);
        $offDates = $data['offDates'] ?? [];
        $extraDates = $data['extraDates'] ?? [];
        DB::transaction(function () use ($keeper, $days, $syncExceptions, $offDates, $extraDates) {
            $profile = GoalkeeperProfile::whereKey($keeper->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $profile);
            $profile->weeklyHours()->delete();
            $profile->weeklyHours()->createMany(array_map(fn ($day) => [
                'weekday' => $day['weekday'], 'start_time' => $day['start'], 'end_time' => $day['end'],
            ], $days));

            if ($syncExceptions) {
                $schedule = collect($days)->keyBy('weekday');
                $today = AvailabilityCalendar::today()->toDateString();
                $maxDate = AvailabilityCalendar::maxDate()->toDateString();
                $effectiveOffDates = array_values(array_filter($offDates, fn ($date) => $schedule->has(CarbonImmutable::parse($date)->dayOfWeekIso)));
                $effectiveExtraDates = array_values(array_filter($extraDates, fn ($date) => ! $schedule->has(CarbonImmutable::parse($date)->dayOfWeekIso)));

                $profile->availabilities()->whereBetween('available_on', [$today, $maxDate])->delete();
                $profile->availabilityExceptions()->whereBetween('unavailable_on', [$today, $maxDate])->delete();
                $profile->availabilities()->createMany(array_map(fn ($date) => ['available_on' => $date], $effectiveExtraDates));
                $profile->availabilityExceptions()->createMany(array_map(fn ($date) => ['unavailable_on' => $date], $effectiveOffDates));
            }
        });

        return to_route('dashboard')->with('weekly_hours_status', __('Orari javor u ruajt. Kërkimi përsërit disponueshmërinë për 30 ditët e ardhshme.'));
    }
}
