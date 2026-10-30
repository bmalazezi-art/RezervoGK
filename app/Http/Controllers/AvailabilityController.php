<?php

namespace App\Http\Controllers;

use App\Models\GoalkeeperProfile;
use App\Support\AvailabilityCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AvailabilityController extends Controller
{
    public function store(Request $request, GoalkeeperProfile $keeper): RedirectResponse
    {
        Gate::authorize('update', $keeper);
        $data = $request->validate([
            'date' => AvailabilityCalendar::dateRules(),
            'available' => ['sometimes', 'boolean'],
        ]);
        $available = $request->boolean('available', true);

        DB::transaction(function () use ($keeper, $data, $available) {
            $profile = GoalkeeperProfile::whereKey($keeper->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $profile);

            if ($available) {
                $profile->availabilityExceptions()->whereDate('unavailable_on', $data['date'])->delete();
                $profile->availabilities()->firstOrCreate(['available_on' => $data['date']]);
            } else {
                $profile->availabilities()->whereDate('available_on', $data['date'])->delete();
                $profile->availabilityExceptions()->firstOrCreate(['unavailable_on' => $data['date']]);
            }
        });

        return to_route('dashboard')->with('status', $available ? __('Disponueshmëria u ruajt.') : __('Data u shënua si e zënë.'));
    }

    public function destroy(GoalkeeperProfile $keeper, string $date): RedirectResponse
    {
        Gate::authorize('update', $keeper);
        Validator::make(['date' => $date], ['date' => AvailabilityCalendar::dateRules(false)])->validate();
        $keeper->availabilities()->where('available_on', $date)->delete();

        return to_route('dashboard')->with('status', __('Data u hoq nga disponueshmëria.'));
    }
}
