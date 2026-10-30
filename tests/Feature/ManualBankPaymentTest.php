<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\ManualPayment;
use App\Models\PaymentAudit;
use App\Models\Subscription;
use App\Models\User;
use App\Support\AvailabilityCalendar;
use App\Support\GoalkeeperEligibility;
use App\Support\GoalkeeperTrial;
use App\Support\ManualPaymentService;
use Carbon\CarbonImmutable;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManualBankPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private string $configuredReceiptRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise preserved paid-mode code only in this isolated test process.
        config(['marketplace.free_launch' => false]);
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
        config(['payments.bank' => ['enabled' => true, 'beneficiary' => 'QA ONLY Recipient', 'bankName' => 'QA ONLY Bank', 'account' => 'QA-NOT-A-REAL-ACCOUNT', 'bic' => 'QA-ONLY']]);
        $this->configuredReceiptRoot = config('filesystems.disks.receipts.root');
        Storage::fake('receipts');
        $this->seed(GoalkeeperDemoSeeder::class);
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['trial_started_at' => now()->subDays(31), 'trial_ends_at' => now()->subDay()])->save();
        $profile = GoalkeeperProfile::firstOrFail();
        $profile->user()->associate($this->owner);
        $profile->save();
        $profile->availabilities()->create(['available_on' => '2026-10-21']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    private function issue(): ManualPayment
    {
        return app(ManualPaymentService::class)->issue($this->owner);
    }

    private function pending(?UploadedFile $file = null): ManualPayment
    {
        $payment = $this->issue();
        $this->actingAs($this->owner)->post('/payments/'.$payment->id.'/submit', ['paid_on' => AvailabilityCalendar::today()->toDateString(), 'sender_note' => 'QA transfer', ...($file ? ['receipt' => $file] : [])])->assertRedirect('/dashboard');

        return $payment->fresh();
    }

    private function approval(string $reference = 'QA-STATEMENT-1'): array
    {
        return ['decision' => 'approved', 'bank_verified' => true, 'received_amount' => '7.00', 'bank_transaction_reference' => $reference, 'received_on' => AvailabilityCalendar::today()->toDateString(), 'review_note' => 'Verified using QA-only statement'];
    }

    private function approve(ManualPayment $payment, string $reference = 'QA-STATEMENT-1'): void
    {
        $this->actingAs($this->admin)->post('/admin/payments/'.$payment->id.'/review', $this->approval($reference))->assertRedirect('/admin/payments?status=pending');
    }

    public function test_local_match_currency_never_changes_the_eur_subscription_charge(): void
    {
        $city = City::whereHas('country', fn ($query) => $query->where('code', 'AL'))->firstOrFail();
        $this->owner->goalkeeperProfile->update(['city_id' => $city->id, 'currency' => 'ALL', 'price_cents' => 120050]);
        $payment = $this->pending();
        $this->assertSame(700, $payment->amount_cents);
        $this->assertSame('EUR', $payment->currency);
        $this->approve($payment);
        $period = $payment->fresh()->subscription;
        $this->assertSame(2592000, (int) $period->starts_at->diffInSeconds($period->ends_at));
        $this->assertSame('ALL', $this->owner->goalkeeperProfile->fresh()->currency);
        $this->assertSame(120050, $this->owner->goalkeeperProfile->fresh()->price_cents);
    }

    public function test_bank_configuration_is_required_and_role_and_prices_are_server_owned(): void
    {
        config(['payments.bank.enabled' => false]);
        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('bankTransfer.configured', false));
        $this->post('/payments')->assertStatus(503);
        $this->assertDatabaseCount('manual_payments', 0);
        config(['payments.bank.enabled' => true]);
        $this->postJson('/payments', ['user_id' => $this->admin->id, 'amount_cents' => 1, 'status' => 'approved'])->assertUnprocessable();
        $payment = $this->issue();
        config(['payments.bank.enabled' => false]);
        $this->post('/payments/'.$payment->id.'/submit', ['paid_on' => '2026-10-21'])->assertStatus(503);
        $this->assertSame('draft', $payment->fresh()->status);
        $this->assertFalse($this->owner->is_admin);
        $this->assertSame(700, $payment->amount_cents);
        $this->assertSame(2592000, $payment->duration_seconds);
    }

    public function test_references_are_unique_stable_and_bank_instructions_are_snapshotted(): void
    {
        $payment = $this->issue();
        $this->assertSame($payment->id, $this->issue()->id);
        $this->assertMatchesRegularExpression('/^RGK-[0-9A-Z]{26}$/', $payment->reference);
        config(['payments.bank.account' => 'CHANGED-QA-ACCOUNT']);
        $this->assertSame('QA-NOT-A-REAL-ACCOUNT', $payment->fresh()->bank_snapshot['account']);
        $other = User::factory()->create();
        $profile = GoalkeeperProfile::findOrFail(2);
        $profile->user()->associate($other);
        $profile->save();
        $this->assertNotSame($payment->reference, app(ManualPaymentService::class)->issue($other)->reference);
        $this->assertSame(1, $payment->audits()->where('event', 'issued')->count());
    }

    public function test_pending_submission_even_with_receipt_never_publishes_expired_owner(): void
    {
        $payment = $this->pending(UploadedFile::fake()->image('receipt.png', 100, 100));
        $this->assertSame('pending', $payment->status);
        Storage::disk('receipts')->assertExists($payment->receipt_path);
        $this->assertSame(0, Subscription::count());
        $this->get('/')->assertInertia(fn (Assert $p) => $p->has('keepers', 0));
        $this->get('/browse?date=2026-10-21')->assertInertia(fn (Assert $p) => $p->has('keepers', 0));
        $this->get('/keepers/'.$this->owner->goalkeeperProfile->id)->assertNotFound();
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('access.isEligible', false)->where('payments.0.status', 'pending')->missing('payments.0.receipt_path'));
        $this->post('/payments/'.$payment->id.'/submit', ['paid_on' => '2026-10-21'])->assertRedirect('/dashboard');
        $this->assertSame(1, $payment->audits()->where('event', 'submitted')->count());
    }

    public function test_receipts_are_private_authorized_attachments_with_audited_downloads(): void
    {
        $payment = $this->pending(UploadedFile::fake()->image('unsafe-name.php.png', 80, 80));
        $this->get('/payments/'.$payment->id.'/receipt')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringContainsString('attachment;', $this->get('/payments/'.$payment->id.'/receipt')->headers->get('content-disposition'));
        $this->actingAs($this->admin)->get('/payments/'.$payment->id.'/receipt')->assertOk();
        $this->actingAs(User::factory()->create())->get('/payments/'.$payment->id.'/receipt')->assertForbidden();
        $this->post('/logout');
        $this->get('/payments/'.$payment->id.'/receipt')->assertRedirect('/login');
        $this->get('/storage/receipts/'.$payment->receipt_path)->assertForbidden();
        $this->assertFalse(Storage::disk('public')->exists('receipts/'.$payment->receipt_path));
        $this->assertNotSame(config('filesystems.disks.local.root'), config('filesystems.disks.receipts.root'));
        $this->assertSame(3, $payment->audits()->where('event', 'receipt_downloaded')->count());
    }

    public function test_receipt_file_types_sizes_and_payment_claim_fields_are_validated(): void
    {
        $payment = $this->issue();
        $this->actingAs($this->owner);
        foreach ([UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
            UploadedFile::fake()->createWithContent('disguised.pdf', '<html>not a receipt</html>'),
            UploadedFile::fake()->image('huge.png', 3001, 2)] as $file) {
            $this->postJson('/payments/'.$payment->id.'/submit', ['paid_on' => '2026-10-21', 'receipt' => $file])->assertJsonValidationErrors('receipt');
        }
        $this->postJson('/payments/'.$payment->id.'/submit', ['paid_on' => '2026-10-22'])->assertJsonValidationErrors('paid_on');
        $this->postJson('/payments/'.$payment->id.'/submit', ['paid_on' => '2026-10-21', 'status' => 'approved', 'receipt_path' => '../secret'])->assertJsonValidationErrors(['status', 'receipt_path']);
        $this->assertCount(0, Storage::disk('receipts')->allFiles());
        $this->assertSame('draft', $payment->fresh()->status);
    }

    public function test_pdf_and_no_receipt_are_accepted_and_images_are_reencoded(): void
    {
        $file = UploadedFile::fake()->image('image.png', 20, 20);
        file_put_contents($file->getRealPath(), file_get_contents($file->getRealPath()).'UNTRUSTED_TRAILER');
        $payment = $this->pending($file);
        $this->assertStringNotContainsString('UNTRUSTED_TRAILER', Storage::disk('receipts')->get($payment->receipt_path));
        $this->actingAs($this->admin)->post('/admin/payments/'.$payment->id.'/review', ['decision' => 'rejected', 'review_note' => 'QA rejection'])->assertRedirect();
        $pdf = UploadedFile::fake()->createWithContent('bank.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        $next = $this->pending($pdf);
        $this->assertSame('application/pdf', $next->receipt_mime);
    }

    public function test_only_admin_can_review_and_admin_cannot_review_own_payment(): void
    {
        $payment = $this->pending();
        $this->get('/admin/payments')->assertForbidden();
        $this->post('/admin/payments/'.$payment->id.'/review', $this->approval())->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/payments')->assertOk()->assertInertia(fn (Assert $p) => $p->has('payments.data', 1));
        $this->owner->forceFill(['is_admin' => true])->save();
        $this->actingAs($this->owner)->post('/admin/payments/'.$payment->id.'/review', $this->approval())->assertForbidden();
        $this->owner->forceFill(['is_admin' => false])->save();
        $other = User::factory()->create();
        $this->actingAs($other)->post('/payments/'.$payment->id.'/submit', ['paid_on' => '2026-10-21'])->assertForbidden();
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('payment_email_deliveries', 0);
    }

    public function test_approval_requires_real_bank_attestation_exact_amount_and_unique_reconciliation_reference(): void
    {
        $payment = $this->pending();
        $this->actingAs($this->admin);
        foreach ([['bank_verified' => false], ['received_amount' => '6.99'], ['received_amount' => '7.01'],
            ['bank_transaction_reference' => ''], ['received_on' => '2026-10-22']] as $change) {
            $this->postJson('/admin/payments/'.$payment->id.'/review', array_replace($this->approval(), $change))->assertUnprocessable();
        }
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->approve($payment);
        $next = $this->pending();
        $this->actingAs($this->admin)->postJson('/admin/payments/'.$next->id.'/review', $this->approval('  qa-statement-1  '))->assertJsonValidationErrors('bank_transaction_reference');
        $this->assertSame('pending', $next->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_approval_grants_exactly_thirty_days_and_replays_do_not_extend_again(): void
    {
        $payment = $this->pending();
        $this->approve($payment);
        $period = $payment->subscription()->firstOrFail();
        $this->assertEquals(2592000, $period->starts_at->diffInSeconds($period->ends_at));
        $this->assertTrue($period->starts_at->equalTo(GoalkeeperTrial::now()));
        $this->get('/keepers/'.$this->owner->goalkeeperProfile->id)->assertOk();
        $this->get('/browse?date=2026-10-21')->assertInertia(fn (Assert $p) => $p->has('keepers', 1));
        $end = $period->ends_at->toIso8601String();
        $this->approve($payment);
        $this->assertSame($end, $period->fresh()->ends_at->toIso8601String());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame(1, $payment->audits()->where('event', 'approved')->count());
        $this->post('/admin/payments/'.$payment->id.'/review', ['decision' => 'rejected', 'review_note' => 'late change'])->assertStatus(409);
        $this->assertSame('approved', $payment->fresh()->status);
        $this->travelTo($period->ends_at->subMicrosecond());
        $this->assertTrue(GoalkeeperEligibility::status($this->owner)['isEligible']);
        $this->travelTo($period->ends_at);
        $this->assertFalse(GoalkeeperEligibility::status($this->owner)['isEligible']);
        $this->get('/keepers/'.$this->owner->goalkeeperProfile->id)->assertNotFound();
        $this->actingAs($this->owner)->get('/dashboard')->assertOk();
    }

    public function test_early_approval_preserves_remaining_trial_then_stacks_multiple_paid_periods(): void
    {
        $trialEnd = GoalkeeperTrial::now()->addDays(12);
        $this->owner->forceFill(['trial_started_at' => now()->subDays(18), 'trial_ends_at' => $trialEnd])->save();
        $first = $this->pending();
        $this->approve($first);
        $period = $first->subscription()->firstOrFail();
        $this->assertTrue($period->starts_at->equalTo($trialEnd));
        $this->assertSame('scheduled', GoalkeeperEligibility::status($this->owner->fresh())['subscriptionStatus']);
        $second = $this->pending();
        $this->approve($second, 'QA-STATEMENT-2');
        $next = $second->subscription()->firstOrFail();
        $this->assertTrue($next->starts_at->equalTo($period->ends_at));
        $this->travelTo($trialEnd);
        $this->assertFalse(GoalkeeperTrial::status($this->owner->fresh())['isEligible']);
        $this->assertTrue(GoalkeeperEligibility::status($this->owner->fresh())['isEligible']);
        $this->assertSame('active', GoalkeeperEligibility::status($this->owner->fresh())['subscriptionStatus']);
        $this->travelTo($period->ends_at);
        $this->assertTrue(GoalkeeperEligibility::status($this->owner->fresh())['isEligible']);
        $this->travelTo($next->ends_at);
        $this->assertSame('expired', GoalkeeperEligibility::status($this->owner->fresh())['subscriptionStatus']);
        $this->assertFalse(GoalkeeperEligibility::status($this->owner->fresh())['isEligible']);
    }

    public function test_rejection_requires_reason_grants_no_access_and_cannot_later_be_approved(): void
    {
        $payment = $this->pending();
        $this->actingAs($this->admin)->postJson('/admin/payments/'.$payment->id.'/review', ['decision' => 'rejected'])->assertJsonValidationErrors('review_note');
        $this->post('/admin/payments/'.$payment->id.'/review', ['decision' => 'rejected', 'review_note' => 'No matching bank transfer'])->assertRedirect();
        $this->post('/admin/payments/'.$payment->id.'/review', ['decision' => 'rejected', 'review_note' => 'repeat'])->assertRedirect();
        $this->assertSame(1, $payment->audits()->where('event', 'rejected')->count());
        $this->post('/admin/payments/'.$payment->id.'/review', $this->approval())->assertStatus(409);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->get('/keepers/'.$this->owner->goalkeeperProfile->id)->assertNotFound();
        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('payments.0.rejectionReason', 'No matching bank transfer'));
        $this->assertNotSame($payment->reference, $this->issue()->reference);
    }

    public function test_pending_record_with_forged_period_does_not_satisfy_public_eligibility(): void
    {
        $payment = $this->pending();
        Subscription::create(['user_id' => $this->owner->id, 'manual_payment_id' => $payment->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(30)]);
        $this->assertFalse(GoalkeeperEligibility::status($this->owner)['isEligible']);
        $this->get('/keepers/'.$this->owner->goalkeeperProfile->id)->assertNotFound();
    }

    public function test_audit_failure_rolls_back_approval_and_receipt_submission_cleans_up_files(): void
    {
        $payment = $this->pending();
        PaymentAudit::creating(function ($audit) {
            if ($audit->event === 'approved') {
                throw new \RuntimeException('QA rollback');
            }
        });
        $this->withoutExceptionHandling();
        try {
            app(ManualPaymentService::class)->review($payment, $this->admin, 'approved', $this->approval());
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('QA rollback', $e->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.PaymentAudit::class);
        }
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
        // Restore a fresh open request after rejecting the first, then simulate a failed audit on submit.
        app(ManualPaymentService::class)->review($payment, $this->admin, 'rejected', ['review_note' => 'QA reset']);
        $draft = $this->issue();
        PaymentAudit::creating(fn () => throw new \RuntimeException('QA file rollback'));
        try {
            app(ManualPaymentService::class)->submit($draft, $this->owner, ['paid_on' => '2026-10-21'], UploadedFile::fake()->image('private.png'));
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('QA file rollback', $e->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.PaymentAudit::class);
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertCount(0, Storage::disk('receipts')->allFiles());
    }

    public function test_non_admin_role_cannot_be_escalated_and_guest_payment_routes_remain_protected(): void
    {
        $this->actingAs($this->owner)->patchJson('/keepers/'.$this->owner->goalkeeperProfile->id, ['is_admin' => true])->assertJsonValidationErrors('is_admin');
        $this->assertFalse($this->owner->fresh()->is_admin);
        $this->post('/logout');
        $this->post('/payments')->assertRedirect('/login');
        $this->get('/admin/payments')->assertRedirect('/login');
        $this->assertDatabaseCount('manual_payments', 0);
    }

    public function test_renewal_after_a_gap_starts_at_approval_time_and_audits_cannot_be_mutated(): void
    {
        $first = $this->pending();
        $this->approve($first);
        $this->travelTo($first->subscription->ends_at->addDays(10));
        $next = $this->pending();
        $this->get('/keepers/'.$this->owner->goalkeeperProfile->id)->assertNotFound();
        $this->approve($next, 'QA-AFTER-GAP');
        $period = $next->subscription()->firstOrFail();
        $this->assertTrue($period->starts_at->equalTo(GoalkeeperTrial::now()));
        $this->assertEquals(2592000, $period->starts_at->diffInSeconds($period->ends_at));
        $audit = $next->audits()->where('event', 'approved')->firstOrFail();
        $this->assertTrue($audit->metadata['bank_verified']);
        try {
            $audit->update(['event' => 'rejected']);
            $this->fail('Audit update must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            $audit->delete();
            $this->fail('Audit deletion must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertSame('approved', $audit->fresh()->event);
    }

    public function test_rejection_ignores_hidden_stale_approval_fields(): void
    {
        $payment = $this->pending();
        $this->actingAs($this->admin)->post('/admin/payments/'.$payment->id.'/review', [
            'decision' => 'rejected', 'review_note' => 'Only a partial transfer was received',
            'bank_verified' => false, 'received_amount' => '6.99',
            'bank_transaction_reference' => '', 'received_on' => '',
        ])->assertRedirect('/admin/payments?status=pending');
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_signed_generic_local_storage_cannot_serve_private_receipts(): void
    {
        $payment = $this->pending(UploadedFile::fake()->image('private.png', 20, 20));
        $this->assertNotSame(config('filesystems.disks.local.root'), config('filesystems.disks.receipts.root'));
        foreach ([config('filesystems.disks.local.root'), config('filesystems.disks.public.root'), public_path()] as $servedRoot) {
            $this->assertFalse(str_starts_with(rtrim($this->configuredReceiptRoot, '/').'/', rtrim($servedRoot, '/').'/'));
        }
        $url = Storage::disk('local')->temporaryUrl('receipts-private/'.$payment->receipt_path, now()->addMinutes(5));
        $this->post('/logout');
        $this->get($url)->assertNotFound();
    }

    public function test_missing_bank_details_block_creation_and_submission_even_when_switch_is_enabled(): void
    {
        $draft = $this->issue();
        $this->actingAs($this->owner);
        foreach (['beneficiary', 'bankName', 'account'] as $field) {
            $original = config('payments.bank.'.$field);
            config(['payments.bank.'.$field => '']);
            $this->post('/payments')->assertStatus(503);
            $this->post('/payments/'.$draft->id.'/submit', ['paid_on' => AvailabilityCalendar::today()->toDateString()])->assertStatus(503);
            config(['payments.bank.'.$field => $original]);
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }
}
