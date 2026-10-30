<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\GoalkeeperProfileReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['7', '30', '90', 'all', 'custom'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
        ]);
        $period = $filters['period'] ?? '30';
        if ($period === 'custom' && (empty($filters['date_from']) || empty($filters['date_to']))) {
            return back()->withErrors(['date_from' => __('Zgjidh datën e fillimit dhe përfundimit.')]);
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        if ($period === 'all') {
            $from = null;
            $to = $today->endOfDay();
        } elseif ($period === 'custom') {
            $from = CarbonImmutable::parse($filters['date_from'], 'UTC')->startOfDay();
            $to = CarbonImmutable::parse($filters['date_to'], 'UTC')->endOfDay();
        } else {
            $days = (int) $period;
            $from = $today->subDays($days - 1);
            $to = $today->endOfDay();
        }
        $countryId = isset($filters['country_id']) ? (int) $filters['country_id'] : null;

        $allUsers = User::query();
        $windowUsers = User::query();
        $profiles = GoalkeeperProfile::query();
        $windowProfiles = GoalkeeperProfile::query();
        if ($countryId) {
            foreach ([$allUsers, $windowUsers] as $query) {
                $query->whereHas('goalkeeperProfile.city', fn ($cities) => $cities->where('country_id', $countryId));
            }
            foreach ([$profiles, $windowProfiles] as $query) {
                $query->whereHas('city', fn ($cities) => $cities->where('country_id', $countryId));
            }
        }
        $this->applyWindow($windowUsers, 'created_at', $from, $to);
        $this->applyWindow($windowProfiles, 'created_at', $from, $to);

        $reportQuery = GoalkeeperProfileReport::query();
        if ($countryId) {
            $reportQuery->whereHas('profile.city', fn ($cities) => $cities->where('country_id', $countryId));
        }
        $windowReports = clone $reportQuery;
        $this->applyWindow($windowReports, 'created_at', $from, $to);

        $publicProfiles = GoalkeeperProfile::publiclyVisible();
        $hiddenProfiles = GoalkeeperProfile::query()->where('admin_hidden', true);
        $unpublishedProfiles = GoalkeeperProfile::query()->where('is_published', false);
        foreach ([$publicProfiles, $hiddenProfiles, $unpublishedProfiles] as $query) {
            if ($countryId) {
                $query->whereHas('city', fn ($cities) => $cities->where('country_id', $countryId));
            }
        }
        $suspendedUsers = clone $allUsers;
        $suspendedUsers->where('is_suspended', true);

        $daily = $this->dailySeriesBounds($period, $from, $to, $windowUsers, $windowProfiles, $windowReports);
        $registrations = $this->dailyCounts($windowUsers, 'created_at', $daily['from'], $daily['to'], $daily['monthly']);
        $profileRegistrations = $this->dailyCounts($windowProfiles, 'created_at', $daily['from'], $daily['to'], $daily['monthly']);
        $reportRows = $this->reportSeries($windowReports, $daily['from'], $daily['to'], $daily['monthly']);

        $profileCountryQuery = GoalkeeperProfile::query()->join('cities', 'cities.id', '=', 'goalkeeper_profiles.city_id')
            ->join('countries', 'countries.id', '=', 'cities.country_id')
            ->select('countries.id', 'countries.name')->selectRaw('COUNT(goalkeeper_profiles.id) as profiles');
        if ($countryId) {
            $profileCountryQuery->where('countries.id', $countryId);
        }
        $profileCountryCounts = $profileCountryQuery->groupBy('countries.id', 'countries.name')->orderByDesc('profiles')->orderBy('countries.name')->get();
        $activeCountryQuery = GoalkeeperProfile::publiclyVisible()->join('cities', 'cities.id', '=', 'goalkeeper_profiles.city_id')
            ->join('countries', 'countries.id', '=', 'cities.country_id')
            ->select('countries.id')->selectRaw('COUNT(goalkeeper_profiles.id) as active');
        if ($countryId) {
            $activeCountryQuery->where('countries.id', $countryId);
        }
        $activeCountryCounts = $activeCountryQuery->groupBy('countries.id')->pluck('active', 'countries.id');

        $totalReports = (clone $windowReports)->count();
        $unresolvedReports = (clone $windowReports)->where('status', 'open')->count();
        $reportStatuses = (clone $windowReports)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $growthDays = $period === 'all' ? 30 : ($period === 'custom' ? max(1, $from->diffInDays($to) + 1) : (int) $period);
        $growthEnd = $period === 'custom' ? $to : $today->endOfDay();
        $growthStart = $period === 'custom' ? $from : $today->subDays($growthDays - 1)->startOfDay();
        $previousEnd = $growthStart->subSecond();
        $previousStart = $growthStart->subDays($growthDays);
        $growth = [
            'currentUsers' => $this->countWindow(clone $allUsers, 'created_at', $growthStart, $growthEnd),
            'previousUsers' => $this->countWindow(clone $allUsers, 'created_at', $previousStart, $previousEnd),
            'currentProfiles' => $this->countWindow(clone $profiles, 'created_at', $growthStart, $growthEnd),
            'previousProfiles' => $this->countWindow(clone $profiles, 'created_at', $previousStart, $previousEnd),
        ];

        return Inertia::render('AdminAnalytics', [
            'filters' => ['period' => $period, 'date_from' => $filters['date_from'] ?? '', 'date_to' => $filters['date_to'] ?? '', 'country_id' => $countryId ?? ''],
            'countries' => City::query()->join('countries', 'countries.id', '=', 'cities.country_id')->select('countries.id', 'countries.name')->distinct()->orderBy('countries.name')->get(),
            'stats' => [
                'totalUsers' => $allUsers->count(), 'newUsers' => $windowUsers->count(),
                'totalProfiles' => $profiles->count(), 'newProfiles' => $windowProfiles->count(),
                'publicProfiles' => $publicProfiles->count(), 'hiddenProfiles' => $hiddenProfiles->count(),
                'unpublishedProfiles' => $unpublishedProfiles->count(), 'suspendedAccounts' => $suspendedUsers->count(),
                'totalReports' => $totalReports, 'unresolvedReports' => $unresolvedReports,
            ],
            'growth' => $growth,
            'charts' => [
                'labels' => $daily['labels'], 'userRegistrations' => $registrations,
                'profileRegistrations' => $profileRegistrations, 'reportVolume' => $reportRows,
                'profileCountries' => $profileCountryCounts->map(fn ($row) => [
                    'country' => $row->name, 'profiles' => (int) $row->profiles,
                    'active' => (int) ($activeCountryCounts[$row->id] ?? 0),
                ])->values(),
                'reportStatuses' => collect(['open', 'reviewed', 'dismissed'])->map(fn ($status) => ['status' => $status, 'total' => (int) ($reportStatuses[$status] ?? 0)]),
            ],
            'timezone' => 'UTC',
        ]);
    }

    private function applyWindow($query, string $column, ?CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from) {
            $query->where($column, '>=', $from->toDateTimeString());
        }
        $query->where($column, '<=', $to->toDateTimeString());
    }

    private function countWindow($query, string $column, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $this->applyWindow($query, $column, $from, $to);

        return $query->count();
    }

    private function dailySeriesBounds(string $period, ?CarbonImmutable $from, CarbonImmutable $to, $users, $profiles, $reports): array
    {
        if ($period === 'all') {
            $first = collect([
                (clone $users)->min('created_at'), (clone $profiles)->min('created_at'), (clone $reports)->min('created_at'),
            ])->filter()->sort()->first();
            $start = $first ? CarbonImmutable::parse($first, 'UTC')->startOfMonth() : $to->startOfMonth();
            $end = $to->startOfMonth();
            $monthly = true;
        } else {
            $start = $from ?? $to->subDays(29)->startOfDay();
            $end = $to->startOfDay();
            $monthly = $start->diffInDays($end) > 120;
            if ($monthly) {
                $start = $start->startOfMonth();
                $end = $end->startOfMonth();
            }
        }

        $labels = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $labels[] = $monthly ? $cursor->format('Y-m') : $cursor->toDateString();
            $cursor = $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return ['from' => $start, 'to' => $monthly ? $end->endOfMonth() : $to, 'monthly' => $monthly, 'labels' => $labels];
    }

    private function dailyCounts($query, string $column, CarbonImmutable $from, CarbonImmutable $to, bool $monthly): array
    {
        $query = clone $query;
        $this->applyWindow($query, $column, $from, $to);
        $rows = $query->selectRaw("DATE($column) as bucket")->selectRaw('COUNT(*) as total')->groupBy('bucket')->get();
        $counts = [];
        foreach ($rows as $row) {
            $key = $monthly ? substr($row->bucket, 0, 7) : $row->bucket;
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->total;
        }

        return $counts;
    }

    private function reportSeries($query, CarbonImmutable $from, CarbonImmutable $to, bool $monthly): array
    {
        $query = clone $query;
        $this->applyWindow($query, 'created_at', $from, $to);
        $rows = $query->selectRaw('DATE(created_at) as bucket')->addSelect('status')->selectRaw('COUNT(*) as total')->groupBy('bucket', 'status')->get();
        $counts = [];
        foreach ($rows as $row) {
            $key = $monthly ? substr($row->bucket, 0, 7) : $row->bucket;
            $counts[$key][$row->status] = (int) $row->total;
        }

        return $counts;
    }
}
