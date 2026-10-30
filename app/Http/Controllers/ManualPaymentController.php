<?php

namespace App\Http\Controllers;

use App\Models\ManualPayment;
use App\Support\AvailabilityCalendar;
use App\Support\BankTransfer;
use App\Support\LaunchModel;
use App\Support\ManualPaymentService;
use App\Support\PrivateReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManualPaymentController extends Controller
{
    public function store(Request $request, ManualPaymentService $service)
    {
        abort_if(LaunchModel::isFree(), 404);
        $request->validate(['user_id' => ['prohibited'], 'amount_cents' => ['prohibited'], 'status' => ['prohibited'], 'reference' => ['prohibited']]);
        $service->issue($request->user());

        return to_route('dashboard')->with('status', __('Referenca u krijua. Transfero saktësisht €7 me këtë referencë.'));
    }

    public function submit(Request $request, ManualPayment $payment, ManualPaymentService $service)
    {
        abort_if(LaunchModel::isFree(), 404);
        abort_unless($request->user()->id === $payment->user_id, 403);
        abort_unless(BankTransfer::configured(), 503);
        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$payment->created_at->setTimezone(config('marketplace.timezone'))->toDateString(),
                'before_or_equal:'.AvailabilityCalendar::today()->toDateString()],
            'sender_note' => ['nullable', 'string', 'max:500'], 'receipt' => PrivateReceipt::rules(),
            'user_id' => ['prohibited'], 'status' => ['prohibited'], 'amount_cents' => ['prohibited'], 'currency' => ['prohibited'],
            'reference' => ['prohibited'], 'receipt_path' => ['prohibited'], 'bank_snapshot' => ['prohibited'],
            'reviewed_by' => ['prohibited'], 'subscription_ends_at' => ['prohibited'],
        ]);
        $service->submit($payment, $request->user(), $data, $request->file('receipt'));

        return to_route('dashboard')->with('status', __('Pagesa është në pritje të verifikimit bankar. Dëshmia nuk e aktivizon profilin.'));
    }

    public function receipt(Request $request, ManualPayment $payment): StreamedResponse
    {
        abort_if(LaunchModel::isFree(), 404);
        abort_unless($request->user()->id === $payment->user_id || $request->user()->can('review-payments'), 403);
        abort_unless($payment->receipt_path && preg_match('/^[a-f0-9-]{36}\.(pdf|jpg|png)$/D', $payment->receipt_path)
            && Storage::disk('receipts')->exists($payment->receipt_path), 404);
        ManualPaymentService::audit($payment, $request->user(), 'receipt_downloaded');

        return Storage::disk('receipts')->download($payment->receipt_path, 'receipt-'.$payment->reference.'.'.pathinfo($payment->receipt_path, PATHINFO_EXTENSION),
            ['Content-Type' => $payment->receipt_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
                'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
