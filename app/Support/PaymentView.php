<?php

namespace App\Support;

use App\Models\ManualPayment;

class PaymentView
{
    public static function owner(ManualPayment $payment): array
    {
        return ['id' => $payment->id, 'reference' => $payment->reference, 'amount' => $payment->amount_cents / 100,
            'currency' => $payment->currency, 'status' => $payment->status, 'bank' => $payment->bank_snapshot,
            'paidOn' => $payment->paid_on?->toDateString(), 'hasReceipt' => $payment->receipt_path !== null,
            'receiptUrl' => $payment->receipt_path ? route('payments.receipt', $payment, false) : null,
            'createdAt' => $payment->created_at->toIso8601String(), 'earliestPaidOn' => $payment->created_at->setTimezone(config('marketplace.timezone'))->toDateString(), 'submittedAt' => $payment->submitted_at?->toIso8601String(),
            'reviewedAt' => $payment->reviewed_at?->toIso8601String(),
            'rejectionReason' => $payment->status === 'rejected' ? $payment->review_note : null,
            'periodStartsAt' => $payment->subscription?->starts_at->toIso8601String(),
            'periodEndsAt' => $payment->subscription?->ends_at->toIso8601String()];
    }
}
