<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\ManualPayment;
use App\Models\PaymentEmailDelivery;
use App\Models\User;
use App\Support\BankTransfer;
use App\Support\AvailabilityCalendar;
use App\Support\ManualPaymentService;
use App\Support\PaymentEmailSender;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FreeLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withCredentials();
        $this->seed(GoalkeeperDemoSeeder::class);
        $this->assertTrue(config('marketplace.free_launch'));
    }

    private function owner(array $attributes = []): User
    {
        $user = User::factory()->create(['password' => 'Free-Launch-QA-12345!']);
        $user->forceFill($attributes)->save();
        $profile = GoalkeeperProfile::firstOrFail();
        $profile->user()->associate($user);
        $profile->save();
        $profile->availabilities()->create(['available_on' => now()->addDay()->toDateString()]);
        $profile->weeklyHours()->create(['weekday' => 6, 'start_time' => '13:00', 'end_time' => '02:00']);

        return $user;
    }

    public static function languages(): array
    {
        return [['sq'], ['en']];
    }

    #[DataProvider('languages')]
    public function test_registration_is_free_secure_published_and_does_not_start_an_expiring_trial(string $locale): void
    {
        $city = City::firstOrFail();
        $this->withCookie(SetLocale::COOKIE, $locale)->post('/register', [
            'name' => 'Portier falas', 'email' => 'free@example.test', 'password' => 'Free-Launch-QA-12345!', 'password_confirmation' => 'Free-Launch-QA-12345!', 'accept_terms' => true,
            'phone' => '+38344123456', 'country_id' => $city->country_id, 'city_id' => $city->id, 'bio' => 'Përmbajtje shqip — unchanged.',
            'price' => '8.25', 'currency' => 'EUR', 'formats' => ['5v5'],
        ])->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $user = User::sole();
        $keeper = $user->goalkeeperProfile;
        $this->assertTrue(Hash::check('Free-Launch-QA-12345!', $user->password));
        $this->assertNull($user->trial_started_at);
        $this->assertNull($user->trial_ends_at);
        $this->assertTrue($keeper->is_published);
        $this->assertFalse($keeper->is_suspended);
        $this->assertSame(825, $keeper->price_cents);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', $locale)->where('freeLaunch', true)
            ->where('access.isEligible', true)->where('access.profileStatus', 'published')->missing('trial')->missing('payments')->missing('bankTransfer')->where('auth.canReviewPayments', false));
        $this->post('/logout');
        $this->travel(400)->days();
        $this->get('/keepers/'.$keeper->id)->assertOk();
        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->has('keepers', 1)->where('keepers.0.bio', 'Përmbajtje shqip — unchanged.'));
        $this->assertDatabaseCount('manual_payments', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public static function trials(): array
    {
        return [['expired'], ['missing'], ['partial'], ['future'], ['active']];
    }

    #[DataProvider('trials')]
    public function test_trial_dates_never_restrict_genuine_profiles_or_date_currency_search(string $state): void
    {
        $attributes = match ($state) {
            'expired' => ['trial_started_at' => now()->subDays(400), 'trial_ends_at' => now()->subDays(370)],
            'future' => ['trial_started_at' => now()->addDay(), 'trial_ends_at' => now()->addDays(31)],
            'active' => ['trial_started_at' => now()->subDay(), 'trial_ends_at' => now()->addDays(29)],
            'partial' => ['trial_started_at' => now()->subDay(), 'trial_ends_at' => null],
            default => ['trial_started_at' => null, 'trial_ends_at' => null],
        };
        $owner = $this->owner($attributes);
        $keeper = $owner->goalkeeperProfile;
        $before = $owner->getRawOriginal();
        foreach (['/', '/browse'] as $url) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page->has('keepers', 1)->where('keepers.0.id', $keeper->id));
        }
        $availableDate = AvailabilityCalendar::today()->addDay()->toDateString();
        $this->get('/browse?date='.$availableDate.'&currency=EUR&max_price=15&format=5v5&city_id='.$keeper->city_id)
            ->assertInertia(fn (Assert $page) => $page->has('keepers', 1)->where('keepers.0.id', $keeper->id)
                ->where('keepers.0.availableOnRequestedDate', true));
        $this->get('/keepers/'.$keeper->id)->assertOk();
        $this->get('/browse?currency=ALL')->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
        $unavailableDate = AvailabilityCalendar::today()->addDays(2);
        while ($unavailableDate->dayOfWeekIso === 6) {
            $unavailableDate = $unavailableDate->addDay();
        }
        $this->get('/browse?date='.$unavailableDate->toDateString())->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 1)->where('keepers.0.id', $keeper->id)
            ->where('keepers.0.availableOnRequestedDate', false));
        $this->post('/login', ['email' => $owner->email, 'password' => 'Free-Launch-QA-12345!'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('access.isEligible', true)->missing('trial')->missing('payments'));
        $this->assertEquals($attributes['trial_ends_at']?->toDateTimeString(), $owner->fresh()->trial_ends_at?->toDateTimeString());
        $this->assertSame($before['password'], $owner->fresh()->password);
    }

    public static function hidden(): array
    {
        return [['unclaimed'], ['unpublished'], ['suspended'], ['both']];
    }

    #[DataProvider('hidden')]
    public function test_ownership_publication_and_suspension_are_enforced_on_all_public_surfaces(string $state): void
    {
        $owner = $state === 'unclaimed' ? null : $this->owner();
        $keeper = GoalkeeperProfile::firstOrFail();
        $keeper->forceFill(['is_published' => ! in_array($state, ['unpublished', 'both']), 'is_suspended' => in_array($state, ['suspended', 'both'])])->save();
        foreach (['/', '/browse'] as $url) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
        }
        $this->get('/keepers/'.$keeper->id)->assertNotFound();
        if ($owner) {
            $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('access.isEligible', false)->missing('payments'));
            $this->put('/keepers/'.$keeper->id.'/weekly-hours', ['days' => [['weekday' => 6, 'start' => '18:00', 'end' => '02:00']]])->assertRedirect('/dashboard');
            $this->patchJson('/keepers/'.$keeper->id, ['is_published' => true, 'is_suspended' => false])->assertJsonValidationErrors(['is_published', 'is_suspended']);
            $this->get('/keepers/'.$keeper->id)->assertNotFound();
        }
        $this->assertDatabaseCount('goalkeeper_profiles', 4);
    }

    public function test_all_payment_http_methods_are_disabled_before_authentication_and_binding_even_for_admin_and_configured_bank(): void
    {
        config(['payments.bank' => ['enabled' => true, 'beneficiary' => 'QA', 'bankName' => 'QA', 'account' => 'QA ONLY', 'bic' => 'QA']]);
        $owner = $this->owner();
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = ManualPayment::create(['user_id' => $owner->id, 'reference' => 'RGK-FREE-QA', 'amount_cents' => 700, 'currency' => 'EUR', 'duration_seconds' => 2592000, 'status' => 'pending', 'bank_snapshot' => []]);
        Storage::fake('receipts');
        $receipt = '12345678-1234-1234-1234-123456789abc.pdf';
        Storage::disk('receipts')->put($receipt, '%PDF-1.4 QA private receipt');
        $payment->forceFill(['receipt_path' => $receipt, 'receipt_mime' => 'application/pdf'])->save();
        $before = $payment->refresh()->getRawOriginal();
        foreach ([null, $owner, $admin] as $actor) {
            if ($actor) {
                $this->actingAs($actor);
            }
            foreach ([['POST', '/payments'], ['POST', '/payments/'.$payment->id.'/submit'], ['GET', '/payments/'.$payment->id.'/receipt'], ['GET', '/admin/payments'], ['POST', '/admin/payments/'.$payment->id.'/review'], ['GET', '/payments/999/receipt'], ['GET', '/payments'], ['GET', '/%70ayments/'.$payment->id.'/receipt'], ['GET', '/admin/%70ayments'], ['POST', '/%70ayments']] as [$method, $url]) {
                $this->call($method, $url)->assertNotFound();
            }
        }
        $this->assertFalse(BankTransfer::configured());
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('payment_audits', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('payment_email_deliveries', 0);
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertSame('%PDF-1.4 QA private receipt', Storage::disk('receipts')->get($receipt));
    }

    public function test_services_and_worker_preserve_historical_payments_and_do_not_send_or_retry(): void
    {
        config(['transactional-mail.enabled' => true, 'mail.default' => 'array', 'app.url' => 'https://qa.example.test', 'mail.from.address' => 'qa@example.test']);
        Mail::fake();
        $owner = $this->owner();
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = ManualPayment::create(['user_id' => $owner->id, 'reference' => 'RGK-OLD', 'amount_cents' => 700, 'currency' => 'EUR', 'duration_seconds' => 2592000, 'status' => 'rejected', 'bank_snapshot' => ['account' => 'Private historical data']]);
        $delivery = PaymentEmailDelivery::create(['manual_payment_id' => $payment->id, 'decision' => 'rejected', 'status' => 'failed', 'attempts' => 5]);
        $before = $payment->refresh()->getRawOriginal();
        $outbox = $delivery->refresh()->getRawOriginal();
        foreach ([fn () => app(ManualPaymentService::class)->issue($owner), fn () => app(ManualPaymentService::class)->submit($payment, $owner, [], null), fn () => app(ManualPaymentService::class)->review($payment, $admin, 'approved', [])] as $operation) {
            try {
                $operation();
                $this->fail('Free launch allowed payment service.');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
        $this->assertSame('disabled', app(PaymentEmailSender::class)->deliver($delivery->id));
        $this->artisan('mail:deliver-transfers', ['--retry-failed' => true])->assertSuccessful();
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertEquals($outbox, $delivery->fresh()->getRawOriginal());
        Mail::assertNothingSent();
        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->missing('payments')->missing('bankTransfer')->missing('trial'));
    }

    public function test_additive_publication_migration_preserves_every_original_field_and_hides_demos(): void
    {
        $owner = $this->owner();
        $rows = DB::table('goalkeeper_profiles')->get()->map(fn ($row) => collect((array) $row)->except(['is_published', 'is_suspended'])->all())->all();
        $migration = require database_path('migrations/2026_10_24_000000_add_profile_publication_controls.php');
        $migration->down();
        $migration->up();
        $after = DB::table('goalkeeper_profiles')->get()->map(fn ($row) => collect((array) $row)->except(['is_published', 'is_suspended'])->all())->all();
        $this->assertSame($rows, $after);
        $this->assertSame([$owner->goalkeeperProfile->id], GoalkeeperProfile::publiclyVisible()->pluck('id')->all());
    }

    public function test_existing_admin_login_cannot_restore_payment_admin_navigation_or_intended_urls(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'Free-Launch-QA-12345!']);
        $this->withSession(['url.intended' => route('admin.payments')])->post('/login', ['email' => $admin->email, 'password' => 'Free-Launch-QA-12345!'])->assertRedirect('/');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.canReviewPayments', false)->where('freeLaunch', true));
        $this->get('/admin/payments')->assertNotFound();
        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_free_launch_does_not_require_bank_admin_or_outbox_activation(): void
    {
        Schema::drop('payment_email_deliveries');
        Artisan::call('launch:status', ['--json' => true]);
        $result = json_decode(Artisan::output(), true);
        $this->assertTrue($result['current_flags']['free_launch']);
        $this->assertFalse($result['current_flags']['bank_submissions_enabled']);
        foreach (['bank_details_present', 'admin_account_exists', 'outbox_migration_installed'] as $key) {
            $this->assertNotContains($key, $result['blocked_checks']);
        }
        $this->assertTrue($result['checks']['publication_controls_installed']);
        $this->assertFalse(Schema::hasTable('payment_email_deliveries'));
        $this->assertDatabaseCount('users', 0);
    }
}
