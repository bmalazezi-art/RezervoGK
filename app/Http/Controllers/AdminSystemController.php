<?php

namespace App\Http\Controllers;

use App\Models\AdminSystemAudit;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminSystemController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['candidate_search' => ['nullable', 'string', 'max:100']]);
        $canManageAdminAccess = $request->user()->isSuperAdmin();
        $users = $canManageAdminAccess
            ? User::query()->where('is_admin', true)->orderBy('name')->get(['id', 'name', 'email', 'created_at'])
            : collect();
        $auditQuery = AdminSystemAudit::with(['actor:id,name', 'target:id,name']);
        if (! $canManageAdminAccess) {
            $auditQuery->where('action', 'settings_updated');
        }
        $audit = $auditQuery->latest('id')->limit(50)->get();
        $candidateSearch = trim((string) ($filters['candidate_search'] ?? ''));
        $candidateQuery = User::query()->where('is_admin', false)->where('is_suspended', false);
        if ($candidateSearch !== '') {
            $candidateQuery->where(fn ($users) => $users->where('name', 'like', "%{$candidateSearch}%")
                ->orWhere('email', 'like', "%{$candidateSearch}%"));
        }

        return Inertia::render('AdminSystem', [
            'settings' => [
                'registrationEnabled' => PlatformSetting::value('registration_enabled', true),
                'announcement' => PlatformSetting::value('announcement', ''),
                'announcementSq' => PlatformSetting::value('announcement_sq', ''),
                'announcementEn' => PlatformSetting::value('announcement_en', ''),
            ],
            'admins' => $users->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'createdAt' => $user->created_at->toIso8601String(), 'isSuperAdmin' => $user->isSuperAdmin()]),
            'canManageAdminAccess' => $canManageAdminAccess,
            'candidateSearch' => $candidateSearch,
            'candidates' => $canManageAdminAccess ? $candidateQuery->latest('id')->limit(100)->get(['id', 'name', 'email'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]) : collect(),
            'audit' => $audit->map(fn (AdminSystemAudit $row) => [
                'id' => $row->id, 'action' => $row->action, 'previousValues' => $row->previous_values,
                'newValues' => $row->new_values, 'reason' => $row->reason,
                'actorName' => $row->actor?->name, 'targetName' => $row->target?->name,
                'createdAt' => $row->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
            'announcement' => ['nullable', 'string', 'max:280'],
            'announcement_sq' => ['nullable', 'string', 'max:280'],
            'announcement_en' => ['nullable', 'string', 'max:280'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $actor = $request->user();

        DB::transaction(function () use ($actor, $data) {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedActor->isSuperAdmin() && ! $lockedActor->is_suspended, 403);
            $keys = ['announcement', 'announcement_sq', 'announcement_en', 'registration_enabled'];
            $settings = PlatformSetting::query()->whereIn('key', $keys)
                ->orderBy('key')->lockForUpdate()->get()->keyBy('key');
            // Older admin clients still submit the original single-language field. Keep that
            // value for compatibility, but never display it publicly without a known locale.
            $legacyAnnouncement = array_key_exists('announcement', $data)
                ? trim((string) ($data['announcement'] ?? ''))
                : (string) ($settings['announcement']->value ?? '');
            $previous = [
                'registration_enabled' => ($settings['registration_enabled']->value ?? '1') === '1',
                'announcement_sq' => (string) ($settings['announcement_sq']->value ?? ''),
                'announcement_en' => (string) ($settings['announcement_en']->value ?? ''),
                'legacy_announcement' => (string) ($settings['announcement']->value ?? ''),
            ];
            $next = [
                'registration_enabled' => (bool) $data['registration_enabled'],
                'announcement_sq' => array_key_exists('announcement_sq', $data)
                    ? trim((string) ($data['announcement_sq'] ?? '')) : (string) ($settings['announcement_sq']->value ?? ''),
                'announcement_en' => array_key_exists('announcement_en', $data)
                    ? trim((string) ($data['announcement_en'] ?? '')) : (string) ($settings['announcement_en']->value ?? ''),
                'legacy_announcement' => $legacyAnnouncement,
            ];
            if ($previous === $next) return;

            foreach ($keys as $key) {
                $setting = $settings->get($key) ?? new PlatformSetting(['key' => $key]);
                $setting->value = match ($key) {
                    'registration_enabled' => $next[$key] ? '1' : '0',
                    'announcement' => $legacyAnnouncement,
                    default => $next[$key],
                };
                $setting->save();
            }
            AdminSystemAudit::create([
                'actor_id' => $lockedActor->id, 'action' => 'settings_updated',
                'previous_values' => $previous, 'new_values' => $next,
                'reason' => trim($data['reason']), 'created_at' => now(),
            ]);
        });

        return back()->with('status', __('Cilësimet u ruajtën.'));
    }

    public function updateAdmin(Request $request, User $user)
    {
        $data = $request->validate([
            'is_admin' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $actor = $request->user();
        $next = (bool) $data['is_admin'];

        DB::transaction(function () use ($actor, $user, $data, $next) {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get();
            $lockedActor = $admins->firstWhere('id', $actor->id);
            abort_unless($lockedActor?->isSuperAdmin() && ! $lockedActor->is_suspended, 403);
            $target = $admins->firstWhere('id', $user->id) ?? User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if((int) $lockedActor->id === (int) $target->id, 403, __('Nuk mund të ndryshoni privilegjet tuaja.'));
            abort_if($target->hasSuperAdminIdentity() && ! $next, 403, __('Qasja e Super Admin nuk mund të hiqet.'));
            if ((bool) $target->is_admin === $next) return;
            if ($target->is_admin && ! $next && $admins->count() <= 1) {
                throw ValidationException::withMessages(['is_admin' => __('Administratori i fundit nuk mund të çaktivizohet.')]);
            }

            $target->is_admin = $next;
            $target->save();
            AdminSystemAudit::create([
                'actor_id' => $lockedActor->id, 'target_id' => $target->id,
                'action' => $next ? 'admin_granted' : 'admin_revoked',
                'previous_values' => ['is_admin' => ! $next], 'new_values' => ['is_admin' => $next],
                'reason' => trim($data['reason']), 'created_at' => now(),
            ]);
        });

        return back()->with('status', $next ? __('Qasja administrative u dha.') : __('Qasja administrative u hoq.'));
    }
}
