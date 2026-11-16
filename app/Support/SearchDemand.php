<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Counts organiser searches per day and place, without storing who searched. */
class SearchDemand
{
    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|curl|wget|python|headless/i';

    public function record(Request $request, ?int $countryId, ?int $cityId, ?string $format, int $results): void
    {
        $user = $request->user();
        if (! $request->hasSession()
            || $request->isMethod('HEAD')
            || (int) $request->query('page', 1) > 1
            || in_array('prefetch', [$request->header('Purpose'), $request->header('Sec-Purpose')], true)
            || $request->header('X-Inertia-Prefetch') === 'true'
            || preg_match(self::BOTS, (string) $request->userAgent())
            || ($user && $user->can('review-reports'))) {
            return;
        }

        $day = AvailabilityCalendar::today()->toDateString();
        $key = [
            'event_date' => $day,
            'country_id' => $countryId ?? 0,
            'city_id' => $cityId ?? 0,
            'format' => (string) ($format ?? ''),
        ];
        $sessionKey = 'search_demand.'.md5(implode('|', $key));
        if ($request->session()->has($sessionKey)) {
            return;
        }

        DB::transaction(function () use ($key, $results) {
            DB::table('search_demand')->insertOrIgnore([...$key, 'searches' => 0, 'zero_results' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('search_demand')->where($key)->update([
                'searches' => DB::raw('searches + 1'),
                'zero_results' => DB::raw('zero_results + '.($results === 0 ? 1 : 0)),
                'updated_at' => now(),
            ]);
        });
        $request->session()->put($sessionKey, true);
    }
}
