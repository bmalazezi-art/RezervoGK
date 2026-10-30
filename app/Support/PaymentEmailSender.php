<?php

namespace App\Support;

use App\Mail\BankTransferDecisionMail;
use App\Models\PaymentEmailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class PaymentEmailSender
{
    public const MAX_ATTEMPTS = 5;

    public function deliver(int $id): string
    {
        if (LaunchModel::isFree()) {
            return 'disabled';
        }
        if (app(TransactionalMail::class)->configurationIssue() !== null) {
            return 'disabled';
        }
        $delivery = DB::transaction(function () use ($id) {
            $row = PaymentEmailDelivery::whereKey($id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['pending', 'processing'], true)
                || ($row->reserved_until && $row->reserved_until->isFuture())
                || ($row->next_attempt_at && $row->next_attempt_at->isFuture())) {
                return null;
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                $row->update(['status' => 'failed', 'reservation' => null, 'reserved_until' => null]);

                return null;
            }
            $row->update(['status' => 'processing', 'attempts' => $row->attempts + 1,
                'reservation' => (string) Str::uuid(), 'reserved_until' => now()->addMinutes(10)]);

            return $row;
        });
        if (! $delivery) {
            return 'skipped';
        }
        try {
            $payment = $delivery->payment()->with(['user', 'subscription'])->firstOrFail();
            if ($payment->status !== $delivery->decision || ! $payment->user || ($payment->status === 'approved' && ! $payment->subscription)) {
                throw new \RuntimeException('Decision unavailable');
            }
            $zone = config('marketplace.timezone');
            $locale = $payment->audits()->where('event', 'submitted')->latest('id')->first()?->metadata['locale'] ?? 'sq';
            $locale = in_array($locale, ['sq', 'en'], true) ? $locale : 'sq';
            Mail::mailer(config('mail.default'))->to($payment->user->email)->send((new BankTransferDecisionMail([
                'decision' => $delivery->decision, 'reference' => $payment->reference,
                'startsAt' => $payment->subscription?->starts_at->setTimezone($zone)->format($locale === 'en' ? 'd/m/Y H:i' : 'd.m.Y H:i'),
                'endsAt' => $payment->subscription?->ends_at->setTimezone($zone)->format($locale === 'en' ? 'd/m/Y H:i' : 'd.m.Y H:i'),
                'timezone' => $zone, 'dashboardUrl' => TransactionalMail::url('dashboard'),
            ]))->locale($locale));
            $changes = ['status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null];
        } catch (Throwable) {
            TransactionalMail::recordFailure('bank_decision_delivery');
            $changes = ['status' => $delivery->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                'next_attempt_at' => now()->addMinutes([1, 5, 15, 60, 240][$delivery->attempts - 1])];
        }
        PaymentEmailDelivery::whereKey($id)->where('reservation', $delivery->reservation)
            ->update([...$changes, 'reservation' => null, 'reserved_until' => null]);

        return $changes['status'];
    }
}
