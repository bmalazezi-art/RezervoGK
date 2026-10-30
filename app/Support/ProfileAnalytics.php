<?php

namespace App\Support;

use App\Models\GoalkeeperProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileAnalytics
{
    public const EVENTS = ['view', 'whatsapp', 'phone'];

    public function record(Request $request, GoalkeeperProfile $profile, string $event): void
    {
        abort_unless(in_array($event, self::EVENTS, true), 422);
        abort_unless(GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists(), 404);

        $user = $request->user();
        if (! $request->hasSession()
            || $request->isMethod('HEAD')
            || $request->header('Purpose') === 'prefetch'
            || $request->header('Purpose') === 'prerender'
            || $request->header('Sec-Purpose') === 'prefetch'
            || $request->header('X-Inertia-Prefetch') === 'true'
            || ($user && ($user->id === $profile->user_id || $user->can('review-payments')))) {
            return;
        }

        $day = AvailabilityCalendar::today()->toDateString();
        $sessionKey = "profile_analytics.{$profile->id}.{$event}.{$day}";
        if ($request->session()->has($sessionKey)) {
            return;
        }

        DB::transaction(function () use ($profile, $day, $event) {
            $values = ['goalkeeper_profile_id' => $profile->id, 'event_date' => $day, 'event_type' => $event];
            DB::table('goalkeeper_profile_analytics')->insertOrIgnore([...$values, 'event_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('goalkeeper_profile_analytics')->where($values)->increment('event_count');
        });

        // Only a per-profile, per-event, per-day flag is kept in the ordinary session.
        $request->session()->put($sessionKey, true);
    }

    public function dashboard(GoalkeeperProfile $profile): array
    {
        $today = AvailabilityCalendar::today();
        $rows = DB::table('goalkeeper_profile_analytics')->where('goalkeeper_profile_id', $profile->id)->get();
        $views = $rows->where('event_type', 'view');

        return [
            'totalViews' => (int) $views->sum('event_count'),
            'views7Days' => (int) $views->where('event_date', '>=', $today->subDays(6)->toDateString())->sum('event_count'),
            'views30Days' => (int) $views->where('event_date', '>=', $today->subDays(29)->toDateString())->sum('event_count'),
            'whatsappClicks' => (int) $rows->where('event_type', 'whatsapp')->sum('event_count'),
            'phoneClicks' => (int) $rows->where('event_type', 'phone')->sum('event_count'),
        ];
    }
}
