<?php

namespace App\Http\Controllers;

use App\Models\ManualPayment;
use App\Support\AvailabilityCalendar;
use App\Support\LaunchModel;
use App\Support\ManualPaymentService;
use App\Support\PaymentView;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminPaymentController extends Controller
{
    public function index(Request $request)
    {
        abort_if(LaunchModel::isFree(), 404);
        $data = $request->validate(['status' => ['nullable', Rule::in(['draft', 'pending', 'approved', 'rejected'])]]);
        $status = $data['status'] ?? 'pending';
        $payments = ManualPayment::with(['user', 'subscription', 'audits'])->where('status', $status)->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('AdminPayments', ['filter' => $status,
            'payments' => $payments->through(fn ($payment) => [...PaymentView::owner($payment),
                'owner' => ['id' => $payment->user_id, 'name' => $payment->user->name, 'email' => $payment->user->email],
                'senderNote' => $payment->sender_note, 'reviewNote' => $payment->review_note,
                'bankTransactionReference' => $payment->bank_transaction_reference,
                'audits' => $payment->audits->map(fn ($audit) => ['id' => $audit->id, 'event' => $audit->event,
                    'actorId' => $audit->actor_id, 'at' => $audit->created_at->toIso8601String(), 'metadata' => $audit->metadata])->all()]),
            'today' => AvailabilityCalendar::today()->toDateString()]);
    }

    public function review(Request $request, ManualPayment $payment, ManualPaymentService $service)
    {
        abort_if(LaunchModel::isFree(), 404);
        abort_if($request->user()->id === $payment->user_id, 403);
        if (is_string($request->input('bank_transaction_reference'))) {
            $request->merge(['bank_transaction_reference' => mb_strtoupper(preg_replace('/\s+/u', ' ', trim($request->input('bank_transaction_reference'))))]);
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'bank_verified' => ['exclude_unless:decision,approved', 'required_if:decision,approved', 'accepted_if:decision,approved'],
            'received_amount' => ['exclude_unless:decision,approved', 'required_if:decision,approved', 'nullable', 'numeric', 'size:7'],
            'bank_transaction_reference' => ['exclude_unless:decision,approved', 'required_if:decision,approved', 'nullable', 'string', 'max:120',
                Rule::unique('manual_payments', 'bank_transaction_reference')->ignore($payment->id)],
            'received_on' => ['exclude_unless:decision,approved', 'required_if:decision,approved', 'nullable', 'date_format:Y-m-d',
                'after_or_equal:'.$payment->created_at->setTimezone(config('marketplace.timezone'))->toDateString(),
                'before_or_equal:'.AvailabilityCalendar::today()->toDateString()],
            'review_note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ]);
        try {
            $service->review($payment, $request->user(), $data['decision'], $data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['bank_transaction_reference' => __('Ky transaksion është verifikuar më parë. Rifresko listën.')]);
        }

        return to_route('admin.payments', ['status' => 'pending'])->with('status', $data['decision'] === 'approved' ? __('Pagesa u miratua vetëm pas verifikimit bankar.') : __('Pagesa u refuzua; qasja nuk u aktivizua.'));
    }
}
