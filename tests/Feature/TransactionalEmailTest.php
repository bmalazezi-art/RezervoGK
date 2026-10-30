<?php

namespace Tests\Feature;

use App\Mail\BankTransferDecisionMail;
use App\Models\ManualPayment;
use App\Models\PaymentEmailDelivery;
use App\Models\User;
use App\Support\BankTransfer;
use App\Support\ManualPaymentService;
use App\Support\PaymentEmailSender;
use App\Support\TransactionalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LocalSmtpSink;
use Tests\TestCase;

class TransactionalEmailTest extends TestCase
{
    use RefreshDatabase;

    private ?LocalSmtpSink $sink = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise preserved paid-mode code only in this isolated test process.
        config(['marketplace.free_launch' => false]);
        $this->withoutVite();
        config(['transactional-mail.enabled' => true, 'app.url' => 'https://keepers.example.test',
            'mail.default' => 'array', 'mail.from.address' => 'qa@example.test']);
    }

    protected function tearDown(): void
    {
        Mail::purge('smtp');
        $this->sink?->stop();
        parent::tearDown();
    }

    private function smtp(string $mode = 'accept'): void
    {
        $this->sink = new LocalSmtpSink($mode);
        config(['mail.default' => 'smtp', 'transactional-mail.qa_local_smtp' => true,
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $this->sink->port,
                'scheme' => 'smtp', 'username' => null, 'password' => null, 'timeout' => 2, 'require_tls' => false, 'auto_tls' => false],
            'app.url' => 'http://127.0.0.1:8010']);
        Mail::purge('smtp');
    }

    private function decision(string $decision = 'approved'): ManualPayment
    {
        $owner = User::factory()->create(['email' => 'keeper-'.$decision.'@example.test']);
        $owner->forceFill(['trial_started_at' => now()->subDays(31), 'trial_ends_at' => now()->subDay()])->save();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $payment = ManualPayment::create(['user_id' => $owner->id, 'reference' => 'RGK-QA-'.bin2hex(random_bytes(5)),
            'amount_cents' => 700, 'currency' => 'EUR', 'duration_seconds' => BankTransfer::DURATION_SECONDS,
            'status' => 'pending', 'bank_snapshot' => ['account' => 'private-bank-account'],
            'receipt_path' => 'private-receipt-path', 'sender_note' => 'private-sender-note']);
        app(ManualPaymentService::class)->review($payment, $admin, $decision, ['bank_verified' => true,
            'received_amount' => '7.00', 'bank_transaction_reference' => 'PRIVATE-TRANSACTION',
            'received_on' => now()->toDateString(), 'review_note' => 'private-review-note']);

        return $payment->fresh();
    }

    public function test_reset_is_delivered_over_real_loopback_smtp_with_html_and_text_and_usable_canonical_token(): void
    {
        $this->smtp();
        $user = User::factory()->create(['email' => 'keeper@example.test']);
        $this->from('/forgot-password')->post('http://untrusted.example.test/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')->assertSessionHas('status');
        $messages = $this->sink->messages();
        $this->assertCount(1, $messages);
        $message = quoted_printable_decode($messages[0]);
        foreach (['text/html', 'text/plain', 'lang="sq"', 'Rivendos fjalëkalimin', 'skadon pas 60 minutash',
            'http://127.0.0.1:8010/reset-password/', 'To: keeper@example.test'] as $text) {
            $this->assertStringContainsString($text, $message);
        }
        $this->assertStringNotContainsString('untrusted.example.test', $message);
        $this->assertStringNotContainsString('fonts.googleapis', $message);
        preg_match('~/reset-password/([a-zA-Z0-9]+)~', $message, $match);
        $this->assertTrue(Password::tokenExists($user, $match[1]));
        $this->assertNotSame($match[1], DB::table('password_reset_tokens')->value('token'));
        // Check validity without printing or persisting the raw token outside the private sink.
        $this->post('/reset-password', ['email' => $user->email, 'token' => $match[1],
            'password' => 'QA-New-Password-12345', 'password_confirmation' => 'QA-New-Password-12345'])->assertRedirect('/login');
        $this->assertFalse(Password::tokenExists($user->fresh(), $match[1]));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_disabled_or_log_transport_never_writes_reset_links_and_returns_same_generic_status(): void
    {
        Notification::fake();
        Log::spy();
        $user = User::factory()->create();
        config(['transactional-mail.enabled' => false]);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertRedirect('/forgot-password');
        $status = session('status');
        $this->post('/forgot-password', ['email' => 'absent@example.test'])->assertSessionHas('status', $status);
        config(['transactional-mail.enabled' => true, 'mail.default' => 'log']);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', $status);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Log::shouldHaveReceived('warning')->with('Transactional email unavailable.', ['kind' => 'password_reset_configuration'])->twice();
    }

    public function test_smtp_reset_rejection_is_generic_sanitized_and_allows_a_new_request(): void
    {
        $this->smtp('reject');
        Log::spy();
        $user = User::factory()->create(['email' => 'keeper@example.test']);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $status = session('status');
        $this->post('/forgot-password', ['email' => 'absent@example.test'])->assertSessionHas('status', $status);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame([], $this->sink->messages());
        Log::shouldHaveReceived('warning')->with('Transactional email unavailable.', ['kind' => 'password_reset_delivery'])->once();
    }

    public function test_required_tls_refuses_a_plaintext_smtp_sink_without_leaking_the_reset_token(): void
    {
        $this->smtp();
        config(['mail.mailers.smtp.require_tls' => true]);
        Mail::purge('smtp');
        Log::spy();
        $user = User::factory()->create();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $this->assertSame([], $this->sink->messages());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Log::shouldHaveReceived('warning')->with('Transactional email unavailable.', ['kind' => 'password_reset_delivery'])->once();
    }

    public function test_recovery_responses_block_referrers_caching_and_token_flash(): void
    {
        $response = $this->get('/reset-password/qa-token?email=qa@example.test');
        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertTrue($response->viewData('page')['encryptHistory']);
        $this->from('/reset-password/qa-token')->post('/reset-password', ['email' => 'qa@example.test',
            'token' => 'do-not-flash-token', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('token', session('_old_input', []));
        $this->assertArrayNotHasKey('password', session('_old_input', []));
    }

    public function test_bank_approval_and_rejection_reach_the_local_smtp_sink_without_private_data(): void
    {
        $this->smtp();
        foreach (['approved', 'rejected'] as $decision) {
            $payment = $this->decision($decision);
            $before = $payment->getRawOriginal();
            $delivery = PaymentEmailDelivery::where('manual_payment_id', $payment->id)->sole();
            $this->artisan('mail:deliver-transfers')->assertSuccessful();
            $this->assertSame('sent', $delivery->fresh()->status);
            $this->assertSame('skipped', app(PaymentEmailSender::class)->deliver($delivery->id));
            $this->assertSame($before, $payment->fresh()->getRawOriginal());
        }
        $messages = $this->sink->messages();
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $body = quoted_printable_decode($message);
            foreach (['text/html', 'text/plain', 'Hap panelin', 'http://127.0.0.1:8010/dashboard'] as $text) {
                $this->assertStringContainsString($text, $body);
            }
            foreach (['private-bank-account', 'private-receipt-path', 'private-sender-note', 'private-review-note', 'PRIVATE-TRANSACTION', 'reset-password', 'Content-Disposition: attachment'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->assertStringContainsString('30 ditë', quoted_printable_decode($messages[0]));
        $this->assertStringContainsString('€7 EUR', quoted_printable_decode($messages[0]));
        $this->assertStringContainsString('u refuzua', quoted_printable_decode($messages[1]));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payment_email_deliveries', 2);
    }

    public function test_decision_outbox_is_atomic_and_repeated_review_never_resends_or_regrants(): void
    {
        Mail::fake();
        $payment = $this->decision();
        $before = $payment->subscription->getRawOriginal();
        app(ManualPaymentService::class)->review($payment, User::findOrFail($payment->reviewed_by), 'approved', []);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payment_email_deliveries', 1);
        $this->assertSame($before, $payment->subscription->fresh()->getRawOriginal());
        Mail::assertNothingSent();
        $delivery = PaymentEmailDelivery::sole();
        $this->assertSame('sent', app(PaymentEmailSender::class)->deliver($delivery->id));
        $this->assertSame('skipped', app(PaymentEmailSender::class)->deliver($delivery->id));
        Mail::assertSent(BankTransferDecisionMail::class, 1);
    }

    public function test_rolled_back_decisions_leave_no_outbox_or_subscription(): void
    {
        Event::listen('eloquent.creating: '.PaymentEmailDelivery::class, fn () => throw new \RuntimeException('QA rollback'));
        try {
            $this->decision();
            $this->fail('Expected rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('QA rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('payment_email_deliveries', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('payment_audits', 0);
        $this->assertSame('pending', ManualPayment::sole()->status);
    }

    public function test_additive_outbox_migration_preserves_existing_records_without_backfilling_email(): void
    {
        $payment = $this->decision();
        $before = $payment->getRawOriginal();
        $period = $payment->subscription->getRawOriginal();
        $users = DB::table('users')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_22_000001_create_payment_email_deliveries.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('payment_email_deliveries', 0);
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertSame($period, $payment->subscription->fresh()->getRawOriginal());
        $this->assertSame($users, DB::table('users')->orderBy('id')->get()->toJson());
    }

    public function test_failure_retries_with_backoff_exhausts_and_can_be_requeued_without_changing_subscription(): void
    {
        $this->smtp('reject');
        Log::spy();
        $payment = $this->decision();
        $before = $payment->subscription->getRawOriginal();
        $delivery = PaymentEmailDelivery::sole();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertSame($attempt === 5 ? 'failed' : 'pending', app(PaymentEmailSender::class)->deliver($delivery->id));
            $this->assertSame($attempt, $delivery->fresh()->attempts);
            $this->assertSame('skipped', app(PaymentEmailSender::class)->deliver($delivery->id));
            $this->travel(5)->hours();
        }
        $this->artisan('mail:deliver-transfers', ['--retry-failed' => true])->assertFailed();
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame($before, $payment->subscription->fresh()->getRawOriginal());
        $this->assertSame('approved', $payment->fresh()->status);
        Log::shouldHaveReceived('warning')->with('Transactional email unavailable.', ['kind' => 'bank_decision_delivery'])->times(6);
    }

    public function test_disabled_outbox_stays_pending_and_active_leases_prevent_parallel_sends(): void
    {
        Mail::fake();
        $this->decision('rejected');
        $delivery = PaymentEmailDelivery::sole();
        config(['transactional-mail.enabled' => false]);
        $this->assertSame('disabled', app(PaymentEmailSender::class)->deliver($delivery->id));
        $this->artisan('mail:deliver-transfers', ['--retry-failed' => true])->assertFailed();
        $this->assertSame(0, $delivery->fresh()->attempts);
        config(['transactional-mail.enabled' => true]);
        $delivery->update(['status' => 'processing', 'reserved_until' => now()->addMinutes(10), 'reservation' => 'qa-lease']);
        $this->assertSame('skipped', app(PaymentEmailSender::class)->deliver($delivery->id));
        $this->travel(11)->minutes();
        $this->assertSame('sent', app(PaymentEmailSender::class)->deliver($delivery->id));
        Mail::assertSent(BankTransferDecisionMail::class, 1);
    }

    public static function unsafeConfiguration(): array
    {
        return [
            [['mail.default' => 'log'], 'smtp_required'],
            [['mail.default' => 'failover'], 'smtp_required'],
            [['mail.mailers.smtp.url' => 'smtp://private:secret@elsewhere'], 'smtp_required'],
            [['mail.mailers.smtp.password' => null], 'smtp_security'],
            [['mail.mailers.smtp.require_tls' => false], 'smtp_security'],
            [['mail.mailers.smtp.verify_peer' => false], 'smtp_security'],
            [['mail.from.address' => 'hello@example.com'], 'sender'],
            [['app.url' => 'http://keepers.example.test'], 'canonical_url'],
            [['app.url' => 'https://user:secret@keepers.example.test'], 'canonical_url'],
            [['mail.mailers.smtp.timeout' => 0], 'smtp_settings'],
            [['mail.mailers.smtp.host' => '127.0.0.1', 'transactional-mail.qa_local_smtp' => true], 'local_smtp_requires_qa'],
        ];
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_unsafe_production_configuration_is_blocked(array $changes, string $issue): void
    {
        $this->app['env'] = 'production';
        config(['mail.default' => 'smtp', 'mail.from.address' => 'notifications@keepers.example.test',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587, 'scheme' => 'smtp',
                'username' => 'test-only', 'password' => 'test-only', 'require_tls' => true, 'auto_tls' => true, 'timeout' => 15]]);
        // Test-only placeholder sender is deliberately replaced with a syntactically real-shaped domain.
        config(['mail.from.address' => 'notifications@keepers-qa.invaliddomain']);
        config($changes);
        $this->assertSame($issue, app(TransactionalMail::class)->configurationIssue());
    }

    public function test_status_command_never_prints_credentials_or_configuration_values(): void
    {
        config(['transactional-mail.enabled' => false, 'mail.mailers.smtp.password' => 'private-SMTP-password']);
        $this->artisan('mail:status')->expectsOutput('Configuration: disabled')->expectsOutput('pending: 0')
            ->expectsOutput('processing: 0')->expectsOutput('sent: 0')->expectsOutput('failed: 0')->assertFailed();
    }
}
