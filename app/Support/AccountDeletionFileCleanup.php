<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AccountDeletionFileCleanup
{
    public function enqueue(string $disk, ?string $path): ?int
    {
        if (! $path || ! $this->isManagedPath($disk, $path)) {
            return null;
        }

        DB::table('account_deletion_file_cleanups')->insertOrIgnore([
            'disk' => $disk,
            'path' => $path,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('account_deletion_file_cleanups')->where('disk', $disk)->where('path', $path)->value('id');
    }

    public function process(int $id): string
    {
        return DB::transaction(function () use ($id): string {
            $item = DB::table('account_deletion_file_cleanups')->where('id', $id)->lockForUpdate()->first();
            if (! $item) {
                return 'missing';
            }
            if ($item->next_attempt_at && now()->lt($item->next_attempt_at)) {
                return 'deferred';
            }

            try {
                $storage = Storage::disk($item->disk);
                if (! $storage->delete($item->path) && $storage->exists($item->path)) {
                    throw new \RuntimeException('Storage adapter did not remove the queued file.');
                }
                DB::table('account_deletion_file_cleanups')->where('id', $id)->delete();

                return 'deleted';
            } catch (Throwable $exception) {
                $attempts = (int) $item->attempts + 1;
                $delaySeconds = min(86400, 60 * (2 ** min($attempts - 1, 10)));
                DB::table('account_deletion_file_cleanups')->where('id', $id)->update([
                    'attempts' => $attempts,
                    'next_attempt_at' => now()->addSeconds($delaySeconds),
                    'updated_at' => now(),
                ]);
                Log::warning('Account deletion file cleanup will be retried.', [
                    'cleanup_id' => $id,
                    'disk' => $item->disk,
                    'attempt' => $attempts,
                    'exception' => $exception::class,
                ]);

                return 'retry';
            }
        });
    }

    private function isManagedPath(string $disk, string $path): bool
    {
        return ($disk === 'public' && preg_match('/^keeper-photos\/[a-f0-9-]{36}\.(jpg|png)$/D', $path))
            || ($disk === 'receipts' && preg_match('/^[a-f0-9-]{36}\.(pdf|png|jpg)$/D', $path));
    }
}
