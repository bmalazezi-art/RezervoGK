<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\GoalkeeperProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Where organisers search (and find nobody), and how profiles turn into contacts. */
class AdminInsightsController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['period' => ['nullable', Rule::in(['7', '30', '90'])]]);
        $days = (int) ($data['period'] ?? 30);
        $from = CarbonImmutable::now()->startOfDay()->subDays($days - 1)->toDateString();

        $countries = Country::pluck('name', 'id');
        $cities = City::pluck('name', 'id');
        $supply = GoalkeeperProfile::publiclyVisible()->select('city_id', DB::raw('COUNT(*) as total'))->groupBy('city_id')->pluck('total', 'city_id');

        $demand = DB::table('search_demand')->where('event_date', '>=', $from);
        $totals = (clone $demand)->selectRaw('COALESCE(SUM(searches),0) as searches, COALESCE(SUM(zero_results),0) as zero')->first();
        $places = (clone $demand)->select('country_id', 'city_id', DB::raw('SUM(searches) as searches'), DB::raw('SUM(zero_results) as zero'))
            ->groupBy('country_id', 'city_id')->orderByDesc('zero')->orderByDesc('searches')->limit(15)->get()
            ->map(fn ($row) => [
                'country' => $row->country_id ? ($countries[$row->country_id] ?? null) : null,
                'city' => $row->city_id ? ($cities[$row->city_id] ?? null) : null,
                'searches' => (int) $row->searches,
                'zeroResults' => (int) $row->zero,
                'goalkeepersNow' => $row->city_id ? (int) ($supply[$row->city_id] ?? 0) : null,
            ]);
        $formats = (clone $demand)->where('format', '!=', '')->select('format', DB::raw('SUM(searches) as searches'))
            ->groupBy('format')->orderByDesc('searches')->get()->map(fn ($row) => ['format' => $row->format, 'searches' => (int) $row->searches]);

        $events = DB::table('goalkeeper_profile_analytics')->where('event_date', '>=', $from);
        $byType = (clone $events)->select('event_type', DB::raw('SUM(event_count) as total'))->groupBy('event_type')->pluck('total', 'event_type');
        $views = (int) ($byType['view'] ?? 0);
        $whatsapp = (int) ($byType['whatsapp'] ?? 0);
        $phone = (int) ($byType['phone'] ?? 0);
        $top = (clone $events)->join('goalkeeper_profiles', 'goalkeeper_profiles.id', '=', 'goalkeeper_profile_analytics.goalkeeper_profile_id')
            ->select('goalkeeper_profiles.id', 'goalkeeper_profiles.name', 'goalkeeper_profiles.slug',
                DB::raw("SUM(CASE WHEN event_type = 'view' THEN event_count ELSE 0 END) as views"),
                DB::raw("SUM(CASE WHEN event_type = 'whatsapp' THEN event_count ELSE 0 END) as whatsapp"),
                DB::raw("SUM(CASE WHEN event_type = 'phone' THEN event_count ELSE 0 END) as phone"))
            ->groupBy('goalkeeper_profiles.id', 'goalkeeper_profiles.name', 'goalkeeper_profiles.slug')
            ->orderByRaw("SUM(CASE WHEN event_type IN ('whatsapp','phone') THEN event_count ELSE 0 END) DESC")
            ->orderByDesc('views')->limit(10)->get()
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'url' => '/p/'.$row->slug, 'views' => (int) $row->views, 'whatsapp' => (int) $row->whatsapp, 'phone' => (int) $row->phone]);

        return Inertia::render('AdminInsights', [
            'period' => (string) $days,
            'demand' => [
                'searches' => (int) $totals->searches,
                'zeroResults' => (int) $totals->zero,
                'places' => $places,
                'formats' => $formats,
            ],
            'contacts' => [
                'views' => $views, 'whatsapp' => $whatsapp, 'phone' => $phone,
                'top' => $top,
            ],
        ]);
    }
}
