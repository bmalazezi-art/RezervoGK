<?php

namespace App\Support;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Permanently deletes a goalkeeper account (used by the dashboard and the account:delete command). */
class AccountDeleter
{
    public function __construct(private AccountDeletionFileCleanup $cleanup) {}

    public function delete(User $user): void
    {
        $cleanupIds = DB::transaction(function () use ($user) {
            $lockedUser = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $lockedUser->is_admin && ! $lockedUser->hasSuperAdminIdentity(), 403);
            $profile = GoalkeeperProfile::where('user_id', $lockedUser->id)->lockForUpdate()->firstOrFail();
            $paymentIds = DB::table('manual_payments')->where('user_id', $lockedUser->id)->pluck('id');
            $receipts = DB::table('manual_payments')->whereIn('id', $paymentIds)->whereNotNull('receipt_path')->pluck('receipt_path')->all();

            foreach (DB::table('payment_audits')->whereIn('manual_payment_id', $paymentIds)->get() as $audit) {
                $metadata = json_decode($audit->metadata, true) ?: [];
                $safeMetadata = array_intersect_key($metadata, array_flip(['from', 'to']));
                DB::table('payment_audits')->where('id', $audit->id)->update([
                    'actor_id' => (int) $audit->actor_id === (int) $lockedUser->id ? null : $audit->actor_id,
                    'metadata' => json_encode($safeMetadata),
                ]);
            }
            DB::table('subscriptions')->where('user_id', $lockedUser->id)->update(['user_id' => null]);
            DB::table('manual_payments')->where('user_id', $lockedUser->id)->update([
                'user_id' => null, 'sender_note' => null, 'review_note' => null,
                'bank_transaction_reference' => null, 'bank_snapshot' => json_encode([]),
                'receipt_path' => null, 'receipt_mime' => null,
            ]);

            $photo = $profile->photo_path;
            $cleanupIds = array_filter(array_merge(
                [$this->cleanup->enqueue('public', $photo)],
                array_map(fn (string $receipt) => $this->cleanup->enqueue('receipts', $receipt), $receipts),
            ));
            $profile->delete(); // Cascades profile contacts, availability, analytics, formats, and reports.
            $lockedUser->delete();

            return array_values(array_unique($cleanupIds));
        });

        foreach ($cleanupIds as $cleanupId) {
            try {
                $this->cleanup->process((int) $cleanupId);
            } catch (\Throwable $exception) {
                // The cleanup row was committed with the deletion and remains available to the scheduled worker.
                Log::error('Account deletion cleanup worker could not run immediately.', [
                    'cleanup_id' => $cleanupId,
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
