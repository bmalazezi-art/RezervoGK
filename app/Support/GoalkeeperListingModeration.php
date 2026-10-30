<?php

namespace App\Support;

use App\Models\AdminListingAudit;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GoalkeeperListingModeration
{
    public function setHidden(GoalkeeperProfile $profile, User $actor, bool $hidden, string $reason): bool
    {
        return DB::transaction(function () use ($profile, $actor, $hidden, $reason) {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedProfile = GoalkeeperProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedActor->is_admin && ! $lockedActor->is_suspended, 403);

            if ($lockedProfile->admin_hidden === $hidden) {
                return false;
            }

            $lockedProfile->admin_hidden = $hidden;
            $lockedProfile->save();
            AdminListingAudit::create([
                'goalkeeper_profile_id' => $lockedProfile->id,
                'actor_id' => $lockedActor->id,
                'action' => $hidden ? 'hidden' : 'restored',
                'reason' => trim($reason),
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
