<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TransactionalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LaunchStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.free_launch' => false]);
    }

    public function test_status_is_read_only_reports_blockers_and_never_prints_sensitive_settings(): void
    {
        $user = User::factory()->create();
        $before = $user->getRawOriginal();
        config(['transactional-mail.enabled' => false, 'payments.bank.enabled' => false,
            'payments.bank.account' => 'private-account-value', 'payments.bank.beneficiary' => 'private-beneficiary',
            'mail.mailers.smtp.password' => 'private-password', 'mail.mailers.smtp.username' => 'private-username']);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->assertSame(1, Artisan::call('launch:status', ['--json' => true]));
        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['read_only']);
        $this->assertFalse($result['activation_authorized']);
        $this->assertFalse($result['configuration_checks_passed']);
        $this->assertSame(0, $result['counts']['admins']);
        $this->assertTrue($result['checks']['outbox_migration_installed']);
        $this->assertFalse($result['current_flags']['bank_submissions_enabled']);
        foreach (['private-account-value', 'private-beneficiary', 'private-password', 'private-username', $user->email] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertSame([], $writes);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('manual_payments', 0);
    }

    public function test_missing_outbox_is_reported_without_running_migrations(): void
    {
        Schema::drop('payment_email_deliveries');
        Artisan::call('launch:status', ['--json' => true]);
        $result = json_decode(Artisan::output(), true);
        $this->assertFalse($result['checks']['outbox_migration_installed']);
        $this->assertFalse(Schema::hasTable('payment_email_deliveries'));
        $this->assertNull($result['counts']['pending_email_deliveries']);
    }

    public function test_preflight_rejects_receipts_in_public_or_generic_served_storage(): void
    {
        foreach (['public', 'local'] as $disk) {
            config(['filesystems.disks.receipts.root' => config('filesystems.disks.'.$disk.'.root')]);
            Artisan::call('launch:status', ['--json' => true]);
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['checks']['private_receipts_outside_public_storage']);
        }
    }

    public function test_bank_switch_alone_does_not_mean_submissions_are_active(): void
    {
        config(['payments.bank.enabled' => true, 'payments.bank.beneficiary' => '',
            'payments.bank.bankName' => '', 'payments.bank.account' => '']);
        $this->assertSame(1, Artisan::call('launch:status', ['--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['current_flags']['bank_transfer_switch_enabled']);
        $this->assertFalse($result['current_flags']['bank_submissions_enabled']);
        $this->assertFalse($result['checks']['bank_details_present']);
        $this->assertContains('bank_details_present', $result['blocked_checks']);
    }

    public function test_even_passing_configuration_does_not_authorize_activation_or_change_flags(): void
    {
        User::factory()->create(['is_admin' => true]);
        config(['app.url' => 'https://qa.example.test', 'app.debug' => false, 'session.secure' => true,
            'mail.default' => 'array', 'mail.from.address' => 'qa@example.test', 'transactional-mail.enabled' => true,
            'payments.bank' => ['enabled' => false, 'beneficiary' => 'QA only', 'bankName' => 'QA only', 'account' => 'QA only', 'bic' => '']]);
        $this->mock(TransactionalMail::class, fn ($mock) => $mock->shouldReceive('configurationIssue')->once()->andReturn(null));
        $this->app['env'] = 'production';
        $this->assertSame(0, Artisan::call('launch:status', ['--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['configuration_checks_passed']);
        $this->assertFalse($result['activation_authorized']);
        $this->assertFalse(config('payments.bank.enabled'));
        $this->assertSame(1, User::where('is_admin', true)->count());
    }
}
