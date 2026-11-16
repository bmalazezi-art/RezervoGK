<?php

namespace App\Http\Controllers;

use App\Support\TransactionalMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** One page that says whether backups, email, the scheduler and the disk are healthy. */
class AdminHealthController extends Controller
{
    public function __invoke(TransactionalMail $mail)
    {
        return Inertia::render('AdminHealth', [
            'checks' => [
                'backup' => $this->backup(),
                'mail' => ['ok' => ($issue = $mail->configurationIssue()) === null, 'issue' => $issue],
                'scheduler' => $this->scheduler(),
                'disk' => $this->disk(),
                'cleanup' => ['pending' => DB::table('account_deletion_file_cleanups')->count()],
            ],
            'version' => [
                'commit' => $this->commit(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => app()->environment(),
            ],
            'checkedAt' => now()->toIso8601String(),
        ]);
    }

    private function backup(): array
    {
        $file = (string) config('admin-security.backup_status_file');
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($data) || empty($data['finished_at'])) {
            return ['ok' => false, 'finishedAt' => null, 'bytes' => null, 'keptDays' => null];
        }
        $finished = CarbonImmutable::parse($data['finished_at']);

        return [
            'ok' => $finished->greaterThan(now()->subHours(26)),
            'finishedAt' => $finished->toIso8601String(),
            'bytes' => isset($data['bytes']) ? (int) $data['bytes'] : null,
            'keptDays' => isset($data['kept_days']) ? (int) $data['kept_days'] : null,
        ];
    }

    private function scheduler(): array
    {
        $file = storage_path('framework/scheduler-heartbeat');
        $last = is_file($file) ? CarbonImmutable::createFromTimestamp((int) filemtime($file)) : null;

        return ['ok' => $last !== null && $last->greaterThan(now()->subMinutes(5)), 'lastRunAt' => $last?->toIso8601String()];
    }

    private function disk(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());
        $usedPercent = $free !== false && $total ? (int) round(100 - $free / $total * 100) : null;

        return ['ok' => $usedPercent !== null && $usedPercent < 85, 'freeBytes' => $free === false ? null : (int) $free, 'usedPercent' => $usedPercent];
    }

    private function commit(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));
        if (! is_string($head)) {
            return null;
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $head = trim((string) @file_get_contents(base_path('.git/'.substr($head, 5))));
        }

        return preg_match('/^[0-9a-f]{40}$/', $head) ? substr($head, 0, 7) : null;
    }
}
