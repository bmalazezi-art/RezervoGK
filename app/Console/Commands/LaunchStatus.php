<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\BankTransfer;
use App\Support\LaunchModel;
use App\Support\TransactionalMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LaunchStatus extends Command
{
    protected $signature = 'launch:status {--json : Print sanitized machine-readable checks}';

    protected $description = 'Read-only activation preflight; never changes users, bank settings, payments or email delivery';

    public function handle(TransactionalMail $mail): int
    {
        $url = parse_url((string) config('app.url'));
        $bank = BankTransfer::details();
        $receiptRoot = realpath((string) config('filesystems.disks.receipts.root'));
        $servedRoots = [public_path(), config('filesystems.disks.public.root'), config('filesystems.disks.local.root')];
        $privateReceipts = $receiptRoot !== false && config('filesystems.disks.receipts.serve') === false
            && config('filesystems.disks.receipts.visibility') === 'private';
        foreach ($servedRoots as $root) {
            $resolved = realpath((string) $root);
            if ($resolved !== false && str_starts_with($receiptRoot.'/', rtrim($resolved, '/').'/')) {
                $privateReceipts = false;
            }
        }
        $checks = [
            'production_environment' => app()->environment('production'),
            'debug_disabled' => ! config('app.debug'),
            'canonical_https_url' => $url && ($url['scheme'] ?? '') === 'https' && ! empty($url['host'])
                && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']),
            'secure_session_cookie' => (bool) config('session.secure'),
            'http_only_session_cookie' => (bool) config('session.http_only'),
            'bank_details_present' => $bank['beneficiary'] !== '' && $bank['bankName'] !== '' && $bank['account'] !== '',
            'transactional_mail_configuration' => $mail->configurationIssue() === null,
            'private_receipts_outside_public_storage' => $privateReceipts,
        ];
        $counts = ['admins' => null, 'pending_email_deliveries' => null, 'failed_email_deliveries' => null];
        try {
            $counts['admins'] = User::where('is_admin', true)->count();
            $checks['admin_account_exists'] = $counts['admins'] > 0;
            $checks['outbox_migration_installed'] = Schema::hasTable('payment_email_deliveries')
                && Schema::hasColumns('payment_email_deliveries', ['manual_payment_id', 'decision', 'status', 'attempts', 'reservation', 'reserved_until', 'next_attempt_at', 'sent_at'])
                && DB::table('migrations')->where('migration', '2026_10_22_000001_create_payment_email_deliveries')->exists();
            if ($checks['outbox_migration_installed']) {
                $counts['pending_email_deliveries'] = DB::table('payment_email_deliveries')->whereIn('status', ['pending', 'processing'])->count();
                $counts['failed_email_deliveries'] = DB::table('payment_email_deliveries')->where('status', 'failed')->count();
            }
            $checks['database_available'] = true;
        } catch (Throwable) {
            $checks['database_available'] = false;
            $checks['admin_account_exists'] = false;
            $checks['outbox_migration_installed'] = false;
        }
        if (LaunchModel::isFree()) {
            unset($checks['bank_details_present'], $checks['admin_account_exists'], $checks['outbox_migration_installed']);
            $checks['publication_controls_installed'] = Schema::hasColumns('goalkeeper_profiles', ['is_published', 'is_suspended']);
            $checks['payment_features_disabled'] = ! BankTransfer::configured();
        }
        $blocked = array_keys(array_filter($checks, fn ($ready) => ! $ready));
        $result = [
            'read_only' => true,
            'configuration_checks_passed' => $blocked === [],
            'activation_authorized' => false,
            'checks' => $checks,
            'blocked_checks' => $blocked,
            'current_flags' => ['free_launch' => LaunchModel::isFree(), 'bank_transfer_switch_enabled' => $bank['enabled'], 'bank_submissions_enabled' => BankTransfer::configured(),
                'transactional_mail_enabled' => (bool) config('transactional-mail.enabled')],
            'counts' => $counts,
            'operator_evidence_required' => LaunchModel::isFree() ? ['fresh backup and restore verification', 'SMTP provider and inbox verification', 'owner-approved policies and support details', 'patched production runtime, HTTPS and storage verification'] : ['fresh backup and restore verification', 'approved bank beneficiary/account verification',
                'explicit administrator identity/role approval and secure access controls', 'SMTP provider and inbox verification',
                'scheduler/monitoring and incident procedures', 'owner-approved policies and support details'],
        ];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Read-only checks; this command never authorizes or performs activation.');
            foreach ($checks as $name => $ready) {
                $this->line(($ready ? 'PASS ' : 'BLOCKED ').$name);
            }
            $this->line('Bank submissions enabled: '.(BankTransfer::configured() ? 'yes' : 'no').'; email enabled: '.(config('transactional-mail.enabled') ? 'yes' : 'no'));
            $this->line('Operator evidence remains required; see docs/free-launch.md for the free model.');
        }

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }
}
