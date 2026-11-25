<?php

namespace App\Http\Controllers;

use App\Models\GoalkeeperProfile;
use App\Models\GoalkeeperProfileReport;
use App\Models\User;
use App\Support\AvailabilityCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function __invoke()
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $chartStart = $today->subDays(29);
        $chartEnd = $today->endOfDay();
        $userDaily = User::query()->whereBetween('created_at', [$chartStart, $chartEnd])
            ->selectRaw('DATE(created_at) as day')->selectRaw('COUNT(*) as total')->groupBy('day')->pluck('total', 'day');
        $profileDaily = GoalkeeperProfile::query()->whereBetween('created_at', [$chartStart, $chartEnd])
            ->selectRaw('DATE(created_at) as day')->selectRaw('COUNT(*) as total')->groupBy('day')->pluck('total', 'day');
        $growth = [];
        for ($day = $chartStart; $day <= $today; $day = $day->addDay()) {
            $key = $day->toDateString();
            $growth[] = ['date' => $key, 'users' => (int) ($userDaily[$key] ?? 0), 'profiles' => (int) ($profileDaily[$key] ?? 0)];
        }
        $userStats = User::query()->selectRaw('COUNT(*) as total_users')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_suspended = 1 THEN 1 ELSE 0 END), 0) as suspended_users')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END), 0) as registrations_7_days', [$today->subDays(6)->toDateTimeString(), $chartEnd->toDateTimeString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END), 0) as registrations_30_days', [$chartStart->toDateTimeString(), $chartEnd->toDateTimeString()])
            ->first();
        $profileStats = GoalkeeperProfile::query()->selectRaw('COUNT(*) as total_profiles')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END), 0) as published_profiles')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END), 0) as unpublished_profiles')
            ->selectRaw('COALESCE(SUM(CASE WHEN admin_hidden = 1 THEN 1 ELSE 0 END), 0) as hidden_profiles')
            ->first();

        // Profile views and contact clicks, counted once per visitor session, profile, event and day (see ProfileAnalytics).
        $marketDay = AvailabilityCalendar::today();
        $since7 = $marketDay->subDays(6)->toDateString();
        $since30 = $marketDay->subDays(29)->toDateString();
        $activity = DB::table('goalkeeper_profile_analytics')->select('goalkeeper_profile_id')
            ->selectRaw("SUM(CASE WHEN event_type = 'view' AND event_date = ? THEN event_count ELSE 0 END) as views_today", [$marketDay->toDateString()])
            ->selectRaw("SUM(CASE WHEN event_type = 'view' AND event_date >= ? THEN event_count ELSE 0 END) as views_7", [$since7])
            ->selectRaw("SUM(CASE WHEN event_type = 'view' AND event_date >= ? THEN event_count ELSE 0 END) as views_30", [$since30])
            ->selectRaw("SUM(CASE WHEN event_type = 'view' THEN event_count ELSE 0 END) as views_total")
            ->selectRaw("SUM(CASE WHEN event_type = 'whatsapp' THEN event_count ELSE 0 END) as whatsapp")
            ->selectRaw("SUM(CASE WHEN event_type = 'phone' THEN event_count ELSE 0 END) as phone")
            ->groupBy('goalkeeper_profile_id')->get()->keyBy('goalkeeper_profile_id');
        $publicIds = GoalkeeperProfile::publiclyVisible()->pluck('id')->flip();
        $profileActivity = GoalkeeperProfile::query()->get(['id', 'name'])->map(function (GoalkeeperProfile $profile) use ($activity, $publicIds) {
            $row = $activity[$profile->id] ?? null;

            return [
                'id' => $profile->id, 'name' => $profile->name, 'isPublic' => isset($publicIds[$profile->id]),
                'viewsToday' => (int) ($row->views_today ?? 0), 'views7Days' => (int) ($row->views_7 ?? 0),
                'views30Days' => (int) ($row->views_30 ?? 0), 'viewsTotal' => (int) ($row->views_total ?? 0),
                'whatsapp' => (int) ($row->whatsapp ?? 0), 'phone' => (int) ($row->phone ?? 0),
            ];
        })->sortByDesc(fn (array $row) => [$row['viewsTotal'], $row['whatsapp'] + $row['phone']])->values();

        return Inertia::render('AdminDashboard', [
            'stats' => [
                'totalUsers' => (int) $userStats->total_users,
                'totalProfiles' => (int) $profileStats->total_profiles,
                'activeProfiles' => GoalkeeperProfile::publiclyVisible()->count(),
                'publishedProfiles' => (int) $profileStats->published_profiles,
                'unpublishedProfiles' => (int) $profileStats->unpublished_profiles,
                'suspendedUsers' => (int) $userStats->suspended_users,
                'hiddenProfiles' => (int) $profileStats->hidden_profiles,
                'pendingReports' => GoalkeeperProfileReport::where('status', 'open')->count(),
                'newRegistrations7Days' => (int) $userStats->registrations_7_days,
                'newRegistrations30Days' => (int) $userStats->registrations_30_days,
            ],
            'usersByCountry' => User::query()
                ->leftJoin('goalkeeper_profiles', 'goalkeeper_profiles.user_id', '=', 'users.id')
                ->leftJoin('cities', 'cities.id', '=', 'goalkeeper_profiles.city_id')
                ->leftJoin('countries', 'countries.id', '=', 'cities.country_id')
                ->selectRaw("COALESCE(countries.name, 'Unassigned') as country, COUNT(DISTINCT users.id) as total")
                ->groupBy('countries.id', 'countries.name')
                ->orderByDesc('total')->orderBy('country')
                ->get()->map(fn ($row) => ['country' => $row->country, 'total' => (int) $row->total]),
            'recentRegistrations' => User::query()->latest('created_at')->limit(6)->get(['id', 'name', 'email', 'created_at'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'createdAt' => $user->created_at->toIso8601String()]),
            'recentReports' => GoalkeeperProfileReport::with('profile:id,name')->where('status', 'open')->latest('id')->limit(6)->get()
                ->map(fn (GoalkeeperProfileReport $report) => ['id' => $report->id, 'profileId' => $report->goalkeeper_profile_id, 'profileName' => $report->profile?->name, 'reason' => $report->reason, 'createdAt' => $report->created_at->toIso8601String()]),
            'growth' => $growth,
            'profileActivity' => $profileActivity,
            'profileVisibility' => [
                'published' => (int) $profileStats->published_profiles,
                'unpublished' => (int) $profileStats->unpublished_profiles,
            ],
        ]);
    }
}
