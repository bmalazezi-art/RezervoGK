<?php

namespace App\Console\Commands;

use App\Models\AdminSystemAudit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DesignateSuperAdmin extends Command
{
    protected $signature = 'admin:designate-super-admin {--reason= : Required reason recorded in the administrator audit log}';

    protected $description = 'Grant administrator access to the configured Super Admin account without creating users';

    public function handle(): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('Provide an audit reason between 10 and 500 characters using --reason.');

            return self::INVALID;
        }

        $result = DB::transaction(function () use ($reason) {
            $matches = User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [User::SUPER_ADMIN_EMAIL])
                ->orderBy('id')->lockForUpdate()->limit(2)->get();

            if ($matches->isEmpty()) {
                return ['status' => 'missing'];
            }
            if ($matches->count() !== 1) {
                return ['status' => 'ambiguous'];
            }

            /** @var User $user */
            $user = $matches->first();
            $wasAdmin = (bool) $user->is_admin;
            if (! $wasAdmin) {
                $user->is_admin = true;
                $user->save();
            }

            $hasDesignationAudit = AdminSystemAudit::query()
                ->where('target_id', $user->id)
                ->where('action', 'super_admin_designated')
                ->exists();

            if (! $wasAdmin || ! $hasDesignationAudit) {
                AdminSystemAudit::create([
                    'actor_id' => null,
                    'target_id' => $user->id,
                    'action' => 'super_admin_designated',
                    'previous_values' => ['is_admin' => $wasAdmin],
                    'new_values' => ['is_admin' => true],
                    'reason' => $reason,
                    'created_at' => now(),
                ]);
            }

            return ['status' => $wasAdmin ? 'already_admin' : 'promoted', 'user_id' => $user->id];
        });

        if ($result['status'] === 'missing') {
            $this->warn('No account matches the configured Super Admin email. Create it through normal registration, then run this command again.');

            return self::FAILURE;
        }
        if ($result['status'] === 'ambiguous') {
            $this->error('Multiple accounts match the normalized Super Admin email. No account was changed.');

            return self::FAILURE;
        }

        $this->info($result['status'] === 'promoted'
            ? 'The configured Super Admin account now has administrator access; the action was audited.'
            : 'The configured Super Admin account already has administrator access; no duplicate designation audit was created.');

        return self::SUCCESS;
    }
}
