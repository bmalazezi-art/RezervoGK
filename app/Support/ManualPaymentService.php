<?php

namespace App\Support;

use App\Models\ManualPayment;
use App\Models\PaymentAudit;
use App\Models\PaymentEmailDelivery;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManualPaymentService
{
    public static function audit(ManualPayment $payment, User $actor, string $event, array $metadata = []): void
    {
        PaymentAudit::create(['manual_payment_id' => $payment->id, 'actor_id' => $actor->id,
            'event' => $event, 'metadata' => $metadata, 'created_at' => GoalkeeperTrial::now()]);
    }

    public function issue(User $owner): ManualPayment
    {
        abort_if(LaunchModel::isFree(), 404);
        abort_unless($owner->goalkeeperProfile, 403);
        abort_unless(BankTransfer::configured(), 503, __('Të dhënat bankare nuk janë konfiguruar.'));

        return DB::transaction(function () use ($owner) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $open = ManualPayment::where('user_id', $owner->id)->whereIn('status', ['draft', 'pending'])->first();
            if ($open) {
                return $open;
            }
            $payment = ManualPayment::create(['user_id' => $owner->id, 'reference' => 'RGK-'.Str::ulid(),
                'amount_cents' => BankTransfer::AMOUNT_CENTS, 'currency' => 'EUR', 'duration_seconds' => BankTransfer::DURATION_SECONDS,
                'status' => 'draft', 'bank_snapshot' => BankTransfer::details()]);
            self::audit($payment, $owner, 'issued', ['amount_cents' => 700, 'currency' => 'EUR']);

            return $payment;
        });
    }

    public function submit(ManualPayment $bound, User $owner, array $data, ?UploadedFile $receipt): void
    {
        abort_if(LaunchModel::isFree(), 404);
        abort_unless(BankTransfer::configured(), 503);
        $path = null;
        try {
            DB::transaction(function () use ($bound, $owner, $data, $receipt, &$path) {
                User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $payment = ManualPayment::whereKey($bound->id)->lockForUpdate()->firstOrFail();
                abort_unless($payment->user_id === $owner->id, 403);
                if ($payment->status === 'pending') {
                    return;
                }
                abort_unless($payment->status === 'draft', 409);
                [$path, $mime] = $receipt ? app(PrivateReceipt::class)->store($receipt) : [null, null];
                $payment->update(['status' => 'pending', 'paid_on' => $data['paid_on'], 'sender_note' => $data['sender_note'] ?? null,
                    'receipt_path' => $path, 'receipt_mime' => $mime, 'submitted_at' => GoalkeeperTrial::now()]);
                self::audit($payment, $owner, 'submitted', ['has_receipt' => $path !== null, 'locale' => app()->getLocale()]);
            });
        } catch (Throwable $e) {
            app(PrivateReceipt::class)->delete($path);
            throw $e;
        }
    }

    public function review(ManualPayment $bound, User $admin, string $decision, array $data): void
    {
        abort_if(LaunchModel::isFree(), 404);
        abort_unless($admin->is_admin && $admin->id !== $bound->user_id, 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        DB::transaction(function () use ($bound, $admin, $decision, $data) {
            $owner = User::whereKey($bound->user_id)->lockForUpdate()->firstOrFail();
            $payment = ManualPayment::whereKey($bound->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === $decision) {
                return;
            }
            abort_unless($payment->status === 'pending', 409);
            $metadata = ['from' => 'pending', 'to' => $decision];
            if ($decision === 'approved') {
                if (! filter_var($data['bank_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) || EuroPrice::cents((string) ($data['received_amount'] ?? '0')) !== 700) {
                    throw ValidationException::withMessages(['bank_verified' => __('Kërkohet verifikimi i €7 EUR në bankë.')]);
                }
                abort_unless($payment->amount_cents === 700 && $payment->currency === 'EUR' && $payment->duration_seconds === BankTransfer::DURATION_SECONDS, 409);
                $reference = $data['bank_transaction_reference'];
                if (ManualPayment::where('bank_transaction_reference', $reference)->whereKeyNot($payment->id)->exists()) {
                    throw ValidationException::withMessages(['bank_transaction_reference' => __('Ky transaksion është verifikuar më parë.')]);
                }
                $now = GoalkeeperTrial::now()->startOfSecond();
                $last = GoalkeeperEligibility::approvedPeriods($owner->subscriptions()->getQuery())->orderBy('ends_at', 'desc')->first();
                $start = $now;
                foreach ([$owner->trial_ends_at, $last?->ends_at] as $end) {
                    if ($end && $end->greaterThan($start)) {
                        $start = $end;
                    }
                }
                $subscription = Subscription::create(['user_id' => $owner->id, 'manual_payment_id' => $payment->id,
                    'starts_at' => $start, 'ends_at' => $start->addSeconds(BankTransfer::DURATION_SECONDS)]);
                $payment->bank_transaction_reference = $reference;
                $payment->received_on = $data['received_on'];
                $metadata += ['subscription_id' => $subscription->id, 'starts_at' => $subscription->starts_at->toIso8601String(),
                    'ends_at' => $subscription->ends_at->toIso8601String(), 'verified_amount_cents' => 700, 'currency' => 'EUR', 'bank_verified' => true,
                    'bank_transaction_reference' => $reference, 'received_on' => $data['received_on']];
            }
            $payment->status = $decision;
            $payment->reviewed_by = $admin->id;
            $payment->reviewed_at = GoalkeeperTrial::now();
            $payment->review_note = $data['review_note'] ?? null;
            $payment->save();
            self::audit($payment, $admin, $decision, $metadata);
            // Enqueue only the decision ID, atomically with its audit/subscription.
            PaymentEmailDelivery::create(['manual_payment_id' => $payment->id, 'decision' => $decision]);
        }, 3);
    }
}
