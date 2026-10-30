<?php

namespace App\Http\Resources;

use App\Support\AvailabilityCalendar;
use App\Support\GoalkeeperPricing;
use App\Support\ProfilePhoto;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalkeeperProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dates = AvailabilityCalendar::upcomingDates($this->resource);
        $requested = $request->query('date');
        $requestedAvailability = is_string($requested) ? in_array($requested, $dates, true) : null;
        $first = is_string($requested) && in_array($requested, $dates, true) ? $requested : ($dates[0] ?? null);
        $today = AvailabilityCalendar::today();
        $label = $first === $today->toDateString() ? __('Sot') : ($first === $today->addDay()->toDateString() ? __('Nesër') : ($first ? CarbonImmutable::parse($first)->format(app()->getLocale() === 'en' ? 'd/m/Y' : 'd.m.Y') : __('Pa data të publikuara')));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city->name.', '.$this->city->country->name,
            'cityName' => $this->city->name, 'countryName' => $this->city->country->name,
            'price' => $this->price_cents / 100,
            'priceAmount' => GoalkeeperPricing::amount($this->price_cents), 'priceMinor' => $this->price_cents, 'currency' => $this->currency,
            'formats' => $this->matchFormats->pluck('code')->all(),
            'photo' => ProfilePhoto::url($this->photo_path, $this->photo_url),
            'available' => $label, 'availableOn' => $first,
            'availabilityKind' => $first === $today->toDateString() ? 'today' : ($first === $today->addDay()->toDateString() ? 'tomorrow' : ($first ? 'date' : 'none')),
            'availabilityDates' => $dates,
            'requestedDate' => is_string($requested) ? $requested : null,
            'availableOnRequestedDate' => $requestedAvailability,
            'weeklyHours' => $this->weeklyHours->map(fn ($hours) => [
                'weekday' => $hours->weekday, 'start' => substr($hours->start_time, 0, 5), 'end' => substr($hours->end_time, 0, 5),
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
