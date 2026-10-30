<?php

namespace App\Http\Controllers;

use App\Http\Resources\GoalkeeperProfileResource;
use App\Models\GoalkeeperProfile;
use App\Support\AvailabilityCalendar;
use App\Support\GoalkeeperPricing;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'keepers' => GoalkeeperProfileResource::collection(GoalkeeperProfile::publiclyVisible()->with(['city.country', 'contact', 'matchFormats', 'upcomingAvailabilities', 'upcomingAvailabilityExceptions', 'weeklyHours'])
                ->where('is_featured', true)->orderBy('id')->limit(3)->get())->resolve(),
            'cities' => GoalkeeperPricing::cities(),
            'countries' => GoalkeeperPricing::countries(),
            'today' => AvailabilityCalendar::today()->toDateString(),
            'initialDate' => AvailabilityCalendar::today()->addDays(2)->toDateString(),
            'maxDate' => AvailabilityCalendar::maxDate()->toDateString(),
        ]);
    }
}
