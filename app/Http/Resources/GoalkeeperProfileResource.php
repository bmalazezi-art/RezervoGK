<?php

namespace App\Http\Resources;

use App\Support\AvailabilityCalendar;
use App\Support\GoalkeeperPricing;
use App\Support\Locales;
use App\Support\ProfilePhoto;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalkeeperProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cities = $this->resource->serviceCities();
        $cityNames = $cities->mapWithKeys(fn ($city) => [(int) $city->id => $city->name])->all();
        // On /browse with a city filter, "available" means available in that city on that day.
        $cityFilter = is_scalar($request->query('city_id')) ? (int) $request->query('city_id') : null;
        $cityFilter = $cityFilter && isset($cityNames[$cityFilter]) && count($cityNames) > 1 ? $cityFilter : null;
        $dateCities = AvailabilityCalendar::upcomingCities($this->resource);
        if ($cityFilter) {
            $dateCities = array_filter($dateCities, fn ($city) => $city === $cityFilter);
        }
        $dates = array_keys($dateCities);
        $requested = $request->query('date');
        $requestedAvailability = is_string($requested) ? in_array($requested, $dates, true) : null;
        $first = is_string($requested) && in_array($requested, $dates, true) ? $requested : ($dates[0] ?? null);
        $today = AvailabilityCalendar::today();
        $label = $first === $today->toDateString() ? __('Sot') : ($first === $today->addDay()->toDateString() ? __('Nesër') : ($first ? CarbonImmutable::parse($first)->format(Locales::dateFormat()) : __('Pa data të publikuara')));

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'url' => $this->resource->publicPath(),
            'name' => $this->name,
            'verifiedByAdmin' => $this->verified_at !== null,
            'city' => $this->city->name.', '.$this->city->country->name,
            'cityName' => $this->city->name, 'countryName' => $this->city->country->name,
            'cities' => $cities->map(fn ($city) => ['id' => (int) $city->id, 'name' => $city->name])->all(),
            'price' => $this->price_cents / 100,
            'priceAmount' => GoalkeeperPricing::amount($this->price_cents), 'priceMinor' => $this->price_cents, 'currency' => $this->currency,
            'formats' => $this->matchFormats->pluck('code')->all(),
            'photo' => ProfilePhoto::url($this->photo_path, $this->photo_url),
            'available' => $label, 'availableOn' => $first,
            'availabilityKind' => $first === $today->toDateString() ? 'today' : ($first === $today->addDay()->toDateString() ? 'tomorrow' : ($first ? 'date' : 'none')),
            'availabilityDates' => $dates,
            'availableCityName' => $first && count($cityNames) > 1 ? ($cityNames[$dateCities[$first] ?? 0] ?? null) : null,
            'requestedDate' => is_string($requested) ? $requested : null,
            'availableOnRequestedDate' => $requestedAvailability,
            'weeklyHours' => $this->weeklyHours->map(fn ($hours) => [
                'weekday' => $hours->weekday, 'start' => substr($hours->start_time, 0, 5), 'end' => substr($hours->end_time, 0, 5),
                'cityId' => isset($cityNames[(int) $hours->city_id]) ? (int) $hours->city_id : (int) $this->city_id,
                'cityName' => $cityNames[(int) $hours->city_id] ?? $this->city->name,
                'endsNextDay' => substr($hours->end_time, 0, 5) !== '00:00' && $hours->end_time < $hours->start_time,
            ])->all(),
            'phone' => ltrim($this->contact?->phone ?? '', '+'),
            'whatsapp' => $this->contact?->whatsapp,
            'featured' => $this->is_featured,
            'bio' => $this->bio,
            'languages' => $this->languages,
            'experienceYears' => $this->experience_years,
        ];
    }
}
