<?php

namespace App\Http\Controllers;

use App\Models\AdminReportAudit;
use App\Models\GoalkeeperProfile;
use App\Models\GoalkeeperProfileReport;
use App\Models\User;
use App\Support\GoalkeeperListingModeration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminGoalkeeperReportController extends Controller
{
    private const REASONS = ['inaccurate', 'inappropriate', 'spam', 'other'];
    private const STATUSES = ['open', 'reviewed', 'dismissed'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'reason' => ['nullable', Rule::in(self::REASONS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(['created_at', 'status', 'reason'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = GoalkeeperProfileReport::query()->with('profile:id,user_id,name,admin_hidden', 'profile.user:id,name')->withCount('adminAudits');
        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $query->where(fn ($reports) => $reports->where('details', 'like', "%{$term}%")
                ->orWhereHas('profile', fn ($profiles) => $profiles->where('name', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($users) => $users->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $reports = $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate(25)->withQueryString();

        return Inertia::render('AdminReports', [
            'basePath' => $request->is('admin/moderation*') ? '/admin/moderation' : '/admin/reports',
            'reports' => $reports->through(fn (GoalkeeperProfileReport $report) => [
                'id' => $report->id, 'profileId' => $report->goalkeeper_profile_id,
                'profileName' => $report->profile?->name ?? __('Profili i fshirë'),
                'profileHidden' => $report->profile?->admin_hidden ?? false,
                'userName' => $report->profile?->user?->name,
                'reason' => $report->reason, 'details' => $report->details, 'status' => $report->status,
                'createdAt' => $report->created_at->toIso8601String(), 'activityCount' => $report->admin_audits_count,
            ]),
            'filters' => [
                'search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? '', 'reason' => $filters['reason'] ?? '',
                'date_from' => $filters['date_from'] ?? '', 'date_to' => $filters['date_to'] ?? '', 'sort' => $sort, 'direction' => $direction,
            ],
            'statuses' => self::STATUSES,
            'reasons' => self::REASONS,
        ]);
    }

    public function show(Request $request, GoalkeeperProfileReport $report)
    {
        $report->load(['profile.city.country', 'profile.user:id,name,email,is_suspended']);
        $profile = $report->profile;
        $activity = $report->adminAudits()->with('actor:id,name')->latest('id')->limit(100)->get();
        $listingHistory = $profile?->adminListingAudits()->with('actor:id,name')->latest('id')->limit(50)->get() ?? collect();

        return Inertia::render('AdminReportDetails', [
            'basePath' => $request->is('admin/moderation*') ? '/admin/moderation' : '/admin/reports',
            'report' => [
                'id' => $report->id, 'reason' => $report->reason, 'details' => $report->details,
                'status' => $report->status, 'createdAt' => $report->created_at->toIso8601String(),
                'updatedAt' => $report->updated_at->toIso8601String(),
                'reporter' => __('Anonim (raporti nuk ruan identitetin e raportuesit)'),
            ],
            'profile' => $profile ? [
                'id' => $profile->id, 'name' => $profile->name, 'city' => $profile->city?->name,
                'country' => $profile->city?->country?->name, 'adminHidden' => $profile->admin_hidden,
                'isPublished' => $profile->is_published, 'isSuspended' => $profile->is_suspended,
                'user' => $profile->user ? ['id' => $profile->user->id, 'name' => $profile->user->name, 'email' => $profile->user->email, 'isSuspended' => $profile->user->is_suspended] : null,
            ] : null,
            'activity' => $activity->map(fn (AdminReportAudit $entry) => [
                'id' => $entry->id, 'event' => $entry->event, 'fromStatus' => $entry->from_status,
                'toStatus' => $entry->to_status, 'note' => $entry->note,
                'actorName' => $entry->actor?->name, 'createdAt' => $entry->created_at->toIso8601String(),
            ]),
            'listingHistory' => $listingHistory->map(fn ($entry) => [
                'id' => $entry->id, 'action' => $entry->action, 'reason' => $entry->reason,
                'actorName' => $entry->actor?->name, 'createdAt' => $entry->created_at->toIso8601String(),
            ]),
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, GoalkeeperProfileReport $report)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'note' => ['nullable', 'string', 'min:5', 'max:2000'],
            'reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ]);
        if (empty($data['status']) && empty($data['note'])) {
            return back()->withErrors(['note' => __('Shto shënim ose ndrysho statusin.')]);
        }
        if (isset($data['status']) && $data['status'] !== $report->status && empty($data['reason'])) {
            return back()->withErrors(['reason' => __('Shkruaj arsyen e ndryshimit të statusit.')]);
        }

        DB::transaction(function () use ($request, $report, $data) {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $lockedReport = GoalkeeperProfileReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_admin && ! $actor->is_suspended, 403);

            if (isset($data['status']) && $data['status'] !== $lockedReport->status) {
                $oldStatus = $lockedReport->status;
                $lockedReport->status = $data['status'];
                $lockedReport->save();
                AdminReportAudit::create([
                    'goalkeeper_profile_report_id' => $lockedReport->id, 'actor_id' => $actor->id,
                    'event' => 'status_changed', 'from_status' => $oldStatus, 'to_status' => $lockedReport->status,
                    'note' => trim((string) ($data['reason'] ?? '')), 'created_at' => now(),
                ]);
            }
            if (! empty($data['note'])) {
                AdminReportAudit::create([
                    'goalkeeper_profile_report_id' => $lockedReport->id, 'actor_id' => $actor->id,
                    'event' => 'note_added', 'note' => trim($data['note']), 'created_at' => now(),
                ]);
            }
        });

        return back()->with('status', __('Aktiviteti i raportit u ruajt.'));
    }

    public function moderateListing(Request $request, GoalkeeperProfileReport $report, GoalkeeperListingModeration $moderation)
    {
        $data = $request->validate([
            'hidden' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $report->loadMissing('profile');
        abort_unless($report->profile, 404);
        $moderation->setHidden($report->profile, $request->user(), (bool) $data['hidden'], $data['reason']);

        return back()->with('status', $data['hidden'] ? __('Listimi u fsheh.') : __('Listimi u rikthye.'));
    }
}
