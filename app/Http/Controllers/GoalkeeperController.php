<?php

namespace App\Http\Controllers;

use App\Http\Resources\GoalkeeperProfileResource;
use App\Models\City;
use App\Models\Country;
use App\Models\GoalkeeperProfile;
use App\Models\MatchFormat;
use App\Support\AvailabilityCalendar;
use App\Support\GoalkeeperPricing;
use App\Support\ProfilePhoto;
use App\Support\ProfileAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GoalkeeperController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'currency' => ['nullable', 'string', Rule::in(GoalkeeperPricing::CURRENCIES)],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'format' => ['nullable', 'string', Rule::exists('match_formats', 'code')],
            'date' => ['nullable', ...array_slice(AvailabilityCalendar::dateRules(), 1)],
        ]);
        $country = isset($filters['country_id']) ? Country::find($filters['country_id'])?->code
            : (isset($filters['city_id']) ? City::with('country')->find($filters['city_id'])?->country?->code : null);
        $currency = $filters['currency'] ?? GoalkeeperPricing::defaultCurrency($country);
        if ($country && ! in_array($currency, GoalkeeperPricing::allowed($country), true)) {
            throw ValidationException::withMessages(['currency' => __('Kjo monedhë nuk lejohet për vendin e zgjedhur.')]);
        }
        $maxPrice = $filters['max_price'] ?? GoalkeeperPricing::filterDefault($currency);
        $query = GoalkeeperProfile::publiclyVisible()->with(['city.country', 'contact', 'matchFormats', 'upcomingAvailabilities', 'upcomingAvailabilityExceptions', 'weeklyHours'])
            ->where('currency', $currency)->where('price_cents', '<=', GoalkeeperPricing::minorUnits((string) $maxPrice));

        if ($cityId = $filters['city_id'] ?? null) {
            $query->where('city_id', $cityId);
        }
        if ($countryId = $filters['country_id'] ?? null) {
            $query->whereHas('city', fn ($city) => $city->where('country_id', $countryId));
        }
        if ($format = $filters['format'] ?? null) {
            $query->whereHas('matchFormats', fn ($formats) => $formats->where('code', $format));
        }

        $date = $filters['date'] ?? null;

        return Inertia::render('Browse', [
            'keepers' => GoalkeeperProfileResource::collection($query->orderBy('id')->get())->resolve(),
            'cities' => GoalkeeperPricing::cities(),
            'countries' => GoalkeeperPricing::countries(),
            'formats' => MatchFormat::orderBy('code')->pluck('code'),
            'today' => AvailabilityCalendar::today()->toDateString(),
            'maxDate' => AvailabilityCalendar::maxDate()->toDateString(),
            'filters' => [
                'city_id' => isset($cityId) ? (string) $cityId : '',
                'country_id' => isset($countryId) ? (string) $countryId : '',
                'max_price' => (float) $maxPrice, 'currency' => $currency,
                'format' => $format ?? '',
                'date' => $date ?? '',
            ],
        ]);
    }

    public function show(Request $request, GoalkeeperProfile $keeper, ProfileAnalytics $analytics): Response
    {
        abort_unless(GoalkeeperProfile::publiclyVisible()->whereKey($keeper->id)->exists(), 404);
        $data = $request->validate(['date' => ['nullable', ...array_slice(AvailabilityCalendar::dateRules(), 1)]]);
        $analytics->record($request, $keeper, 'view');
        $keeper->load(['city.country', 'contact', 'matchFormats', 'upcomingAvailabilities', 'upcomingAvailabilityExceptions', 'weeklyHours']);

        $appUrl = rtrim(config('app.url'), '/');
        $profileUrl = $appUrl.route('keepers.show', ['keeper' => $keeper->id], false);
        $photoUrl = ProfilePhoto::url($keeper->photo_path, $keeper->photo_url);
        $description = trim($keeper->bio) !== ''
            ? Str::limit(trim($keeper->bio), 220)
            : __('Portier :name nga :city. Shiko profilin dhe kontakto drejtpërdrejt.', ['name' => $keeper->name, 'city' => $keeper->city->name.', '.$keeper->city->country->name]);

        $title = $keeper->name.' | Gjej portier';
        return Inertia::render('Profile', [
            'keeper' => (new GoalkeeperProfileResource($keeper))->resolve(),
            'shareTitle' => $title,
            'shareUrl' => $profileUrl,
            'shareDescription' => $description,
            'shareImage' => Str::startsWith($photoUrl, ['http://', 'https://']) ? $photoUrl : $appUrl.'/'.ltrim($photoUrl, '/'),
            'calendar' => AvailabilityCalendar::week($keeper, $data['date'] ?? null),
            'today' => AvailabilityCalendar::today()->toDateString(),
            'maxDate' => AvailabilityCalendar::maxDate()->toDateString(),
            'selectedDate' => $data['date'] ?? AvailabilityCalendar::today()->toDateString(),
        ])->withViewData([
            'shareTitle' => $title,
            'shareDescription' => $description,
            'shareUrl' => $profileUrl,
            'shareImage' => Str::startsWith($photoUrl, ['http://', 'https://']) ? $photoUrl : $appUrl.'/'.ltrim($photoUrl, '/'),
        ]);
    }
}
