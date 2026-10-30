<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use App\Support\ProfilePhoto;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Support\GoalkeeperListingModeration;

class AdminGoalkeepersController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'visibility' => ['nullable', Rule::in(['public', 'admin_hidden', 'unpublished', 'profile_suspended', 'account_suspended'])],
            'reported' => ['nullable', Rule::in(['reported', 'clear'])],
            'sort' => ['nullable', Rule::in(['name', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = GoalkeeperProfile::query()->with(['city.country', 'user:id,name,email,is_suspended'])
            ->withCount(['reports as open_reports_count' => fn ($reports) => $reports->where('status', 'open')]);
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.trim($filters['search']).'%');
        }
        if (! empty($filters['country_id'])) {
            $query->whereHas('city', fn ($cities) => $cities->where('country_id', $filters['country_id']));
        }
        if (! empty($filters['city_id'])) {
            $query->where('city_id', $filters['city_id']);
        }
        match ($filters['visibility'] ?? null) {
            'public' => $query->whereIn('id', GoalkeeperProfile::publiclyVisible()->select('id')),
            'admin_hidden' => $query->where('admin_hidden', true),
            'unpublished' => $query->where('is_published', false),
            'profile_suspended' => $query->where('is_suspended', true),
            'account_suspended' => $query->whereHas('user', fn ($users) => $users->where('is_suspended', true)),
            default => null,
        };
        if (($filters['reported'] ?? null) === 'reported') {
            $query->whereHas('reports', fn ($reports) => $reports->where('status', 'open'));
        } elseif (($filters['reported'] ?? null) === 'clear') {
            $query->whereDoesntHave('reports', fn ($reports) => $reports->where('status', 'open'));
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $profiles = $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate(25)->withQueryString();
        $visibleIds = GoalkeeperProfile::publiclyVisible()->whereIn('id', $profiles->getCollection()->pluck('id'))->pluck('id')->all();
        $visible = array_fill_keys($visibleIds, true);

        return Inertia::render('AdminGoalkeepers', [
            'profiles' => $profiles->through(fn (GoalkeeperProfile $profile) => [
                'id' => $profile->id, 'name' => $profile->name,
                'photo' => ProfilePhoto::url($profile->photo_path, $profile->photo_url),
                'city' => $profile->city?->name, 'country' => $profile->city?->country?->name,
                'createdAt' => $profile->created_at->toIso8601String(), 'updatedAt' => $profile->updated_at->toIso8601String(),
                'isPublished' => $profile->is_published, 'isSuspended' => $profile->is_suspended,
                'adminHidden' => $profile->admin_hidden, 'userSuspended' => $profile->user?->is_suspended ?? false,
                'isPubliclyVisible' => isset($visible[$profile->id]), 'openReports' => $profile->open_reports_count,
            ]),
            'filters' => [
                'search' => $filters['search'] ?? '', 'country_id' => $filters['country_id'] ?? '',
                'city_id' => $filters['city_id'] ?? '', 'visibility' => $filters['visibility'] ?? '',
                'reported' => $filters['reported'] ?? '', 'sort' => $sort, 'direction' => $direction,
            ],
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
            'cities' => City::query()->when($filters['country_id'] ?? null, fn ($cities, $countryId) => $cities->where('country_id', $countryId))
                ->orderBy('name')->get(['id', 'country_id', 'name']),
        ]);
    }

    public function show(GoalkeeperProfile $profile)
    {
        $profile->load(['city.country', 'user:id,name,email,is_suspended', 'weeklyHours', 'upcomingAvailabilities', 'upcomingAvailabilityExceptions']);
        $reports = $profile->reports()->latest('id')->limit(50)->get(['id', 'reason', 'details', 'status', 'created_at']);
        $audits = $profile->adminListingAudits()->with('actor:id,name')->latest('id')->limit(50)->get();
        $publiclyVisible = GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists();

        return Inertia::render('AdminGoalkeeperDetails', [
            'profile' => [
                'id' => $profile->id, 'name' => $profile->name, 'slug' => $profile->slug,
                'bio' => $profile->bio, 'photo' => ProfilePhoto::url($profile->photo_path, $profile->photo_url),
                'price' => $profile->price_cents / 100, 'currency' => $profile->currency,
                'experienceYears' => $profile->experience_years, 'languages' => $profile->languages ?? [],
                'formats' => $profile->matchFormats()->orderBy('code')->pluck('code'),
                'city' => $profile->city?->name, 'country' => $profile->city?->country?->name,
                'user' => $profile->user ? ['id' => $profile->user->id, 'name' => $profile->user->name, 'email' => $profile->user->email, 'isSuspended' => $profile->user->is_suspended] : null,
                'isPublished' => $profile->is_published, 'isSuspended' => $profile->is_suspended,
                'adminHidden' => $profile->admin_hidden, 'isPubliclyVisible' => $publiclyVisible,
                'createdAt' => $profile->created_at->toIso8601String(), 'updatedAt' => $profile->updated_at->toIso8601String(),
                'weeklyHours' => $profile->weeklyHours->map(fn ($hour) => ['weekday' => $hour->weekday, 'start' => substr($hour->start_time, 0, 5), 'end' => substr($hour->end_time, 0, 5)]),
                'upcomingAvailabilities' => $profile->upcomingAvailabilities->take(30)->map(fn ($row) => $row->available_on->toDateString())->values(),
                'upcomingExceptions' => $profile->upcomingAvailabilityExceptions->take(30)->map(fn ($row) => $row->unavailable_on->toDateString())->values(),
            ],
            'reports' => $reports->map(fn ($report) => ['id' => $report->id, 'reason' => $report->reason, 'details' => $report->details, 'status' => $report->status, 'createdAt' => $report->created_at->toIso8601String()]),
            'moderationHistory' => $audits->map(fn (AdminListingAudit $audit) => ['id' => $audit->id, 'action' => $audit->action, 'reason' => $audit->reason, 'actorName' => $audit->actor?->name, 'createdAt' => $audit->created_at->toIso8601String()]),
        ]);
    }

    public function moderate(Request $request, GoalkeeperProfile $profile, GoalkeeperListingModeration $moderation)
    {
        $data = $request->validate([
            'hidden' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $moderation->setHidden($profile, $request->user(), (bool) $data['hidden'], $data['reason']);

        return back()->with('status', $data['hidden'] ? __('Listimi u fsheh.') : __('Listimi u rikthye.'));
    }
}
