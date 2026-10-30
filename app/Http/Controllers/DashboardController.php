<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGoalkeeperRequest;
use App\Http\Resources\GoalkeeperProfileResource;
use App\Models\GoalkeeperProfile;
use App\Models\MatchFormat;
use App\Support\AvailabilityCalendar;
use App\Support\BankTransfer;
use App\Support\GoalkeeperEligibility;
use App\Support\GoalkeeperPricing;
use App\Support\GoalkeeperTrial;
use App\Support\LaunchModel;
use App\Support\PaymentView;
use App\Support\ProfilePhoto;
use App\Support\ProfileAnalytics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    public function show(Request $request, ProfileAnalytics $analytics): Response
    {
        $profile = $request->user()->goalkeeperProfile;
        abort_unless($profile, 403);
        Gate::authorize('update', $profile);
        $profile->load(['city.country', 'contact', 'matchFormats', 'upcomingAvailabilities', 'upcomingAvailabilityExceptions', 'weeklyHours']);

        return Inertia::render('Dashboard', [
            'keeper' => (new GoalkeeperProfileResource($profile))->resolve(),
            'analytics' => $analytics->dashboard($profile),
            'cities' => GoalkeeperPricing::cities(),
            'cityId' => $profile->city_id,
            'accountEmail' => $request->user()->email,
            'access' => GoalkeeperEligibility::status($request->user()),
            ...(LaunchModel::isFree() ? [] : [
                'trial' => GoalkeeperTrial::status($request->user()),
                'bankTransfer' => ['configured' => BankTransfer::configured(), 'bank' => BankTransfer::details()],
                'payments' => $request->user()->manualPayments()->with('subscription')->latest('id')->limit(20)->get()->map(fn ($payment) => PaymentView::owner($payment)),
            ]),
            'formats' => MatchFormat::orderBy('code')->pluck('code'),
            'availabilityWindow' => AvailabilityCalendar::window($profile),
            'extraDates' => $profile->upcomingAvailabilities
                ->filter(fn ($row) => $row->available_on->toDateString() <= AvailabilityCalendar::maxDate()->toDateString())
                ->map(fn ($row) => $row->available_on->toDateString())->values()->all(),
            'offDates' => $profile->upcomingAvailabilityExceptions
                ->filter(fn ($row) => $row->unavailable_on->toDateString() <= AvailabilityCalendar::maxDate()->toDateString())
                ->map(fn ($row) => $row->unavailable_on->toDateString())->values()->all(),
            'today' => AvailabilityCalendar::today()->toDateString(),
            'maxDate' => AvailabilityCalendar::maxDate()->toDateString(),
        ]);
    }

    public function update(UpdateGoalkeeperRequest $request, GoalkeeperProfile $keeper, ProfilePhoto $photos): RedirectResponse
    {
        $data = $request->validated();
        $path = $request->hasFile('photo') ? $photos->store($request->file('photo')) : null;
        $removePhoto = $request->boolean('remove_photo');
        try {
            $oldPath = DB::transaction(function () use ($data, $keeper, $request, $path, $removePhoto) {
                $profile = GoalkeeperProfile::whereKey($keeper->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('update', $profile);
                $oldPath = $profile->photo_path;
                $profile->fill([
                    'name' => $data['name'], 'city_id' => $data['city_id'], 'bio' => $data['bio'],
                    'price_cents' => GoalkeeperPricing::minorUnits((string) $data['price']), 'currency' => $data['currency'],
                    'experience_years' => $data['experience_years'] ?? $profile->experience_years,
                    'languages' => $data['languages'] ?? $profile->languages,
                ]);
                if ($path || $removePhoto) {
                    $profile->photo_path = $path;
                    $profile->photo_url = $path ? Storage::disk('public')->url($path) : '/keeper-placeholder.svg';
                }
                $profile->save();
                $existingContact = $profile->contact;
                $profile->contact()->updateOrCreate([], [
                    'phone' => $data['phone'],
                    'whatsapp' => isset($data['whatsapp']) ? ltrim($data['whatsapp'], '+') : ($existingContact?->whatsapp ?? ltrim($data['phone'], '+')),
                ]);
                if (isset($data['formats'])) {
                    $profile->matchFormats()->sync(MatchFormat::whereIn('code', $data['formats'])->pluck('id'));
                }
                $request->user()->update(['name' => $data['name']]);

                return $oldPath;
            });
        } catch (Throwable $exception) {
            $photos->delete($path);
            throw $exception;
        }
        if ($path || $removePhoto) {
            $photos->delete($oldPath);
        }

        return to_route('dashboard')->with('status', __('Ndryshimet u ruajtën.'));
    }
}
