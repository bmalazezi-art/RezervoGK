<?php

namespace App\Console\Commands;

use App\Support\AccountDeletionFileCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CleanDeletedAccountFiles extends Command
{
    protected $signature = 'account-deletion:cleanup-files {--limit=100 : Maximum files to process (1–500)}';

    protected $description = 'Retry removal of profile photos and receipts after account deletion';

    public function handle(AccountDeletionFileCleanup $cleanup): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if (! $limit) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }

        try {
            $ids = DB::table('account_deletion_file_cleanups')
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')->limit($limit)->pluck('id');
            $results = ['deleted' => 0, 'retry' => 0, 'deferred' => 0, 'missing' => 0];
            foreach ($ids as $id) {
                $result = $cleanup->process((int) $id);
                $results[$result]++;
            }
            $this->info('Deleted-account file cleanup: '.json_encode($results));

            return $results['retry'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('File cleanup queue unavailable. Check migrations and storage configuration.');

            return self::FAILURE;
        }
    }
}
