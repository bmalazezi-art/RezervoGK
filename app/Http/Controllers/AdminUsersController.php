<?php

namespace App\Http\Controllers;

use App\Models\AdminUserAudit;
use App\Models\City;
use App\Models\Country;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminUsersController extends Controller
{
    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'account_type' => ['nullable', Rule::in(['goalkeeper', 'user', 'admin'])],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date', 'after_or_equal:registered_from'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'sort' => ['nullable', Rule::in(['created_at', 'name', 'email'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = User::query()->with(['goalkeeperProfile.city.country']);
        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"));
        }
        if (($filters['account_type'] ?? null) === 'admin') {
            $query->where('is_admin', true);
        } elseif (($filters['account_type'] ?? null) === 'goalkeeper') {
            $query->where('is_admin', false)->whereHas('goalkeeperProfile');
        } elseif (($filters['account_type'] ?? null) === 'user') {
            $query->where('is_admin', false)->whereDoesntHave('goalkeeperProfile');
        }
        if (! empty($filters['registered_from'])) {
            $query->whereDate('created_at', '>=', $filters['registered_from']);
        }
        if (! empty($filters['registered_to'])) {
            $query->whereDate('created_at', '<=', $filters['registered_to']);
        }
        if (! empty($filters['country_id'])) {
            $query->whereHas('goalkeeperProfile.city', fn ($cities) => $cities->where('country_id', $filters['country_id']));
        }
        if (! empty($filters['city_id'])) {
            $query->whereHas('goalkeeperProfile', fn ($profiles) => $profiles->where('city_id', $filters['city_id']));
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $users = $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate(25)->withQueryString();

        return Inertia::render('AdminUsers', [
            'users' => $users->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'createdAt' => $user->created_at->toIso8601String(),
                'accountType' => $user->is_admin ? 'admin' : ($user->goalkeeperProfile ? 'goalkeeper' : 'user'),
                'isSuspended' => $user->is_suspended,
                'country' => $user->goalkeeperProfile?->city?->country?->name,
                'city' => $user->goalkeeperProfile?->city?->name,
            ]),
            'filters' => [
                'search' => $filters['search'] ?? '', 'account_type' => $filters['account_type'] ?? '',
                'registered_from' => $filters['registered_from'] ?? '', 'registered_to' => $filters['registered_to'] ?? '',
                'country_id' => $filters['country_id'] ?? '', 'city_id' => $filters['city_id'] ?? '',
                'sort' => $sort, 'direction' => $direction,
            ],
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
            'cities' => City::query()->when($filters['country_id'] ?? null, fn ($cities, $countryId) => $cities->where('country_id', $countryId))
                ->orderBy('name')->get(['id', 'country_id', 'name']),
        ]);
    }

    public function show(User $user)
    {
        $user->load(['goalkeeperProfile.city.country', 'goalkeeperProfile.weeklyHours', 'goalkeeperProfile.upcomingAvailabilities', 'goalkeeperProfile.upcomingAvailabilityExceptions']);
        $profile = $user->goalkeeperProfile;
        $reports = $profile?->reports()->latest('id')->limit(50)->get(['id', 'reason', 'details', 'status', 'created_at']) ?? collect();
        $audits = AdminUserAudit::with('actor:id,name')->where('target_id', $user->id)->latest('id')->limit(50)->get();
        $publiclyVisible = $profile ? GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists() : false;

        return Inertia::render('AdminUserDetails', [
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'createdAt' => $user->created_at->toIso8601String(), 'updatedAt' => $user->updated_at->toIso8601String(),
                'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),
                'accountType' => $user->is_admin ? 'admin' : ($profile ? 'goalkeeper' : 'user'),
                'isAdmin' => $user->is_admin, 'isSuspended' => $user->is_suspended,
                'isSuperAdmin' => $user->hasSuperAdminIdentity(),
            ],
            'profile' => $profile ? [
                'id' => $profile->id, 'name' => $profile->name,
                'country' => $profile->city?->country?->name, 'city' => $profile->city?->name,
                'isPublished' => $profile->is_published, 'isSuspended' => $profile->is_suspended,
                'isPubliclyVisible' => $publiclyVisible,
                'weeklyHours' => $profile->weeklyHours->map(fn ($hour) => ['weekday' => $hour->weekday, 'start' => $hour->start_time, 'end' => $hour->end_time]),
                'upcomingAvailabilities' => $profile->upcomingAvailabilities->take(30)->map(fn ($row) => $row->available_on->toDateString())->values(),
                'upcomingExceptions' => $profile->upcomingAvailabilityExceptions->take(30)->map(fn ($row) => $row->unavailable_on->toDateString())->values(),
            ] : null,
            'reports' => $reports->map(fn ($report) => [
                'id' => $report->id, 'reason' => $report->reason, 'details' => $report->details,
                'status' => $report->status, 'createdAt' => $report->created_at->toIso8601String(),
            ]),
            'audit' => $audits->map(fn (AdminUserAudit $audit) => [
                'id' => $audit->id, 'action' => $audit->action, 'reason' => $audit->reason,
                'actorName' => $audit->actor?->name, 'createdAt' => $audit->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function updateSuspension(Request $request, User $user)
    {
        $data = $request->validate([
            'suspended' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $user, $data) {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $target = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_admin && ! $actor->is_suspended, 403);
            // Admin accounts are all protected because the existing schema cannot distinguish root admins.
            abort_if((int) $actor->id === (int) $target->id || $target->is_admin || $target->hasSuperAdminIdentity(), 403);

            $suspended = (bool) $data['suspended'];
            if ($target->is_suspended === $suspended) {
                return;
            }

            $target->is_suspended = $suspended;
            $target->save();
            AdminUserAudit::create([
                'actor_id' => $actor->id, 'target_id' => $target->id,
                'action' => $suspended ? 'suspended' : 'reactivated',
                'reason' => trim($data['reason']), 'created_at' => now(),
            ]);
        });

        return back()->with('status', $data['suspended'] ? __('Llogaria u pezullua.') : __('Llogaria u riaktivizua.'));
    }
}
