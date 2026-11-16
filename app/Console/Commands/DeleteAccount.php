<?php

namespace App\Console\Commands;

use App\Models\AdminSystemAudit;
use App\Models\User;
use App\Support\AccountDeleter;
use Illuminate\Console\Command;

class DeleteAccount extends Command
{
    protected $signature = 'account:delete {email : Email of the account to delete permanently} {--reason= : Required reason recorded in the administrator audit log}';

    protected $description = 'Permanently delete a goalkeeper account (removes admin access first); never the Super Admin';

    public function handle(AccountDeleter $deleter): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('Provide an audit reason between 10 and 500 characters using --reason.');

            return self::INVALID;
        }

        $matches = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->limit(2)->get();
        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty() ? 'No account has that email.' : 'More than one account matches that email. Nothing was deleted.');

            return self::FAILURE;
        }
        /** @var User $user */
        $user = $matches->first();
        if ($user->hasSuperAdminIdentity()) {
            $this->error('The Super Admin account cannot be deleted.');

            return self::FAILURE;
        }
        if (! $user->goalkeeperProfile()->exists()) {
            $this->error('This account has no goalkeeper profile; only goalkeeper accounts can be deleted with this command.');

            return self::FAILURE;
        }

        $this->warn("This permanently deletes {$user->name} <{$user->email}>, their public profile, photo and personal data.");
        $typed = $this->ask('Type the email again to confirm');
        if (mb_strtolower(trim((string) $typed)) !== $email) {
            $this->info('Not confirmed. Nothing was deleted.');

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $user->forceFill(['is_admin' => false])->save();
            AdminSystemAudit::create([
                'actor_id' => null, 'target_id' => $user->id, 'action' => 'admin_revoked',
                'previous_values' => ['is_admin' => true], 'new_values' => ['is_admin' => false],
                'reason' => $reason, 'created_at' => now(),
            ]);
            $this->info('Administrator access removed.');
        }

        $deleter->delete($user->fresh());
        $this->info('Account and goalkeeper profile deleted.');

        return self::SUCCESS;
    }
}
