<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\PaymentEmailDelivery;
use App\Models\User;
use App\Support\AvailabilityCalendar;
use App\Support\Locales;
use App\Support\ManualPaymentService;
use App\Support\PaymentEmailSender;
use Carbon\CarbonImmutable;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LocalSmtpSink;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withCredentials();
        $this->seed(GoalkeeperDemoSeeder::class);
    }

    public function test_default_and_invalid_cookie_fall_back_to_albanian(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'sq'));
        $this->withCookie(SetLocale::COOKIE, 'de')->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'sq'));
        $this->withCookie(SetLocale::COOKIE, 'en')->get('/')->assertSee('<html lang="en">', false)->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
        $this->withCookie(SetLocale::COOKIE, 'mk')->get('/')->assertSee('<html lang="mk">', false)->assertInertia(fn (Assert $page) => $page->where('locale', 'mk'));
    }

    public function test_macedonian_can_be_chosen_and_translates_server_messages_and_validation(): void
    {
        $this->postJson('/locale', ['locale' => 'mk'])->assertOk()->assertExactJson(['locale' => 'mk'])->assertCookie(SetLocale::COOKIE, 'mk');
        $this->withCookie(SetLocale::COOKIE, 'mk')->get('/')->assertSee('<meta name="description" content="Најди портир и контактирај го директно преку WhatsApp или телефон за твојот натпревар.">', false);
        $this->withCookie(SetLocale::COOKIE, 'mk')->postJson('/login', ['email' => '', 'password' => ''])->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Полето е-пошта е задолжително.', 'password' => 'Полето лозинка е задолжително.']);
        $this->withCookie(SetLocale::COOKIE, 'mk')->get('/locale-qa-missing')->assertNotFound()->assertSee('<html lang="mk">', false)->assertSee('Страницата не е пронајдена');
        $this->get('/reset-password/test-only-invalid-token?locale=mk')->assertCookie(SetLocale::COOKIE, 'mk')->assertSee('<html lang="mk">', false);
        app()->setLocale('mk');
        $this->assertSame('ПОН', mb_strtoupper(CarbonImmutable::parse('2026-10-26')->locale('mk')->isoFormat('ddd')));
        $this->assertSame('d.m.Y H:i', Locales::dateFormat(withTime: true));
    }

    public function test_preference_endpoint_is_whitelisted_and_sets_a_private_secure_cookie(): void
    {
        $response = $this->postJson('https://localhost/locale', ['locale' => 'en'])->assertOk()->assertExactJson(['locale' => 'en']);
        $response->assertCookie(SetLocale::COOKIE, 'en');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === SetLocale::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertGreaterThan(time() + 3600 * 24 * 360, $cookie->getExpiresTime());
        foreach (['de', '../en', '<script>', ['en'], ''] as $locale) {
            $this->postJson('/locale', ['locale' => $locale])->assertUnprocessable()->assertCookieMissing(SetLocale::COOKIE);
        }
        $this->get('/locale')->assertMethodNotAllowed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_locale_change_requires_csrf_in_the_web_stack(): void
    {
        // Enable the real CSRF middleware, which Laravel normally bypasses in tests.
        $this->app['env'] = 'local';
        $this->postJson('/locale', ['locale' => 'en'])->assertStatus(419);
    }

    public function test_english_persists_through_registration_dashboard_logout_and_login_without_changing_content(): void
    {
        $this->withCookie(SetLocale::COOKIE, 'en');
        $city = City::where('name', 'Prishtinë')->firstOrFail();
        $this->post('/register', [
            'name' => 'Mirëdita Test', 'email' => 'locale@example.test', 'password' => 'Locale-QA-12345!', 'password_confirmation' => 'Locale-QA-12345!', 'accept_terms' => true,
            'phone' => '+38344123456', 'country_id' => $city->country_id, 'city_id' => $city->id,
            'bio' => 'Përshkrimi im mbetet shqip.', 'price' => '7.25', 'currency' => 'EUR', 'formats' => ['5v5'],
        ])->assertRedirect('/dashboard')->assertSessionHas('status', 'Your account and profile have been created.');
        $owner = User::sole();
        $keeper = $owner->goalkeeperProfile;
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'en')->where('keeper.name', 'Mirëdita Test')->where('keeper.bio', 'Përshkrimi im mbetet shqip.')->where('keeper.languages', ['Shqip'])->where('keeper.priceAmount', '7.25')->where('keeper.currency', 'EUR'));
        $this->post('/logout')->assertRedirect('/')->assertCookieMissing(SetLocale::COOKIE);
        $this->assertGuest();
        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
        $this->post('/login', ['email' => $owner->email, 'password' => 'Locale-QA-12345!'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
        $this->assertSame('Përshkrimi im mbetet shqip.', $keeper->fresh()->bio);
        $this->assertSame(725, $keeper->fresh()->price_cents);
    }

    public function test_english_validation_authentication_weekly_hours_and_bank_disabled_remain_secure(): void
    {
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->postJson('/login', ['email' => 'missing@example.test', 'password' => 'Incorrect-12345!'])->assertUnprocessable()->assertJsonValidationErrors(['email' => 'The email or password is incorrect.']);
        $this->postJson('/register', [])->assertUnprocessable()->assertJsonValidationErrors(['name' => 'The name field is required.', 'password' => 'The password field is required.']);
        $owner = User::factory()->create();
        $keeper = GoalkeeperProfile::firstOrFail();
        $keeper->user()->associate($owner);
        $keeper->save();
        $this->actingAs($owner)->putJson('/keepers/'.$keeper->id.'/weekly-hours', ['days' => [['weekday' => 6, 'start' => '18:00', 'end' => '18:00']]])->assertUnprocessable()->assertJsonValidationErrors(['days.0.end' => 'The end time must differ from the start time.']);
        $this->putJson('/keepers/'.$keeper->id.'/weekly-hours', ['days' => [['weekday' => 6, 'start' => '18:00', 'end' => '02:00']]])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('goalkeeper_weekly_hours', ['goalkeeper_profile_id' => $keeper->id, 'weekday' => 6, 'start_time' => '18:00', 'end_time' => '02:00']);
        config(['payments.bank.enabled' => false]);
        $this->post('/payments')->assertNotFound();
        $this->get('/admin/payments')->assertNotFound();
        $this->assertDatabaseCount('manual_payments', 0);
        $this->assertFalse($owner->fresh()->is_admin);
    }

    public static function languages(): array
    {
        return [['sq'], ['en'], ['mk']];
    }

    #[DataProvider('languages')]
    public function test_all_public_pages_and_recovery_support_both_languages(string $locale): void
    {
        $this->withCookie(SetLocale::COOKIE, $locale);
        foreach (['/', '/browse', '/how-it-works', '/faq', '/contact', '/privacy', '/terms', '/register', '/login', '/forgot-password'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('locale', $locale));
        }
        $this->get('/pricing')->assertRedirect('/');
        $this->get('/reset-password/test-only-invalid-token?locale='.$locale)->assertOk()->assertCookie(SetLocale::COOKIE, $locale)->assertInertia(fn (Assert $page) => $page->where('locale', $locale));
        $this->postJson('/reset-password', ['token' => 'test-only-invalid-token', 'email' => 'absent@example.test', 'password' => 'New-Password-12345!', 'password_confirmation' => 'New-Password-12345!'])->assertUnprocessable()->assertJsonValidationErrors(['email' => ['sq' => 'Lidhja e rivendosjes është e pavlefshme ose ka skaduar.', 'en' => 'The reset link is invalid or has expired.', 'mk' => 'Врската за ресетирање е невалидна или истекла.'][$locale]]);
    }

    public function test_early_missing_route_and_method_errors_use_the_encrypted_cookie_locale(): void
    {
        $this->withCookie(SetLocale::COOKIE, 'en')->get('/locale-qa-missing')->assertNotFound()->assertSee('<html lang="en">', false)->assertSee('Not Found');
        $this->get('/logout')->assertStatus(405)->assertSee('<html lang="en">', false);
        $this->withCookie(SetLocale::COOKIE, 'sq')->get('/logout')->assertStatus(405)->assertSee('Metoda nuk lejohet');
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'not-a-valid-encrypted-cookie')->get('/locale-qa-missing')->assertNotFound()->assertSee('<html lang="sq">', false);
    }

    public function test_reset_link_can_restore_english_in_a_fresh_browser_and_does_not_accept_other_languages(): void
    {
        $this->get('/reset-password/test-only-invalid-token?locale=en')->assertCookie(SetLocale::COOKIE, 'en')->assertSee('<html lang="en">', false);
        $this->get('/reset-password/test-only-invalid-token?locale=de')->assertCookieMissing(SetLocale::COOKIE)->assertInertia(fn (Assert $page) => $page->where('locale', 'sq'));
        $this->get('/?locale=en')->assertInertia(fn (Assert $page) => $page->where('locale', 'sq'));
    }

    #[DataProvider('languages')]
    public function test_reset_and_both_decision_emails_deliver_in_owner_language_over_isolated_loopback_smtp(string $locale): void
    {
        config(['marketplace.free_launch' => false]); // Dormant decision emails remain covered for a future reviewed release.
        $sink = new LocalSmtpSink('accept');
        try {
            config(['transactional-mail.enabled' => true, 'transactional-mail.qa_local_smtp' => true,
                'app.url' => 'http://127.0.0.1:8011', 'mail.default' => 'smtp', 'mail.from.address' => 'qa@example.test',
                'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $sink->port, 'scheme' => 'smtp', 'username' => null, 'password' => null, 'timeout' => 2, 'require_tls' => false, 'auto_tls' => false],
                'payments.bank' => ['enabled' => true, 'beneficiary' => 'QA ONLY', 'bankName' => 'QA ONLY', 'account' => 'QA-NOT-REAL', 'bic' => 'QA']]);
            Mail::purge('smtp');
            $owner = User::factory()->create(['email' => 'locale@example.test']);
            $keeper = GoalkeeperProfile::firstOrFail();
            $keeper->user()->associate($owner);
            $keeper->save();
            $admin = User::factory()->create();
            $admin->forceFill(['is_admin' => true])->save();
            $this->withCookie(SetLocale::COOKIE, $locale)->post('/forgot-password', ['email' => $owner->email])->assertRedirect();
            foreach (['approved', 'rejected'] as $decision) {
                app()->setLocale($locale);
                $payment = app(ManualPaymentService::class)->issue($owner);
                app(ManualPaymentService::class)->submit($payment, $owner, ['paid_on' => AvailabilityCalendar::today()->toDateString()], null);
                $this->assertSame($locale, $payment->audits()->where('event', 'submitted')->sole()->metadata['locale']);
                // The reviewer and queue worker deliberately use the opposite language.
                app()->setLocale($locale === 'en' ? 'sq' : 'en');
                app(ManualPaymentService::class)->review($payment, $admin, $decision, ['bank_verified' => true, 'received_amount' => '7.00', 'bank_transaction_reference' => 'QA-UNIQUE-'.$locale, 'received_on' => AvailabilityCalendar::today()->toDateString(), 'review_note' => 'Shënim privat — mos përkthe.']);
                $delivery = PaymentEmailDelivery::where('manual_payment_id', $payment->id)->sole();
                $this->assertSame('sent', app(PaymentEmailSender::class)->deliver($delivery->id));
                $this->assertSame('skipped', app(PaymentEmailSender::class)->deliver($delivery->id));
                $this->assertSame($locale === 'en' ? 'sq' : 'en', app()->getLocale());
                $this->assertSame('Shënim privat — mos përkthe.', $payment->fresh()->review_note);
            }
            $messages = array_map('quoted_printable_decode', $sink->messages());
            $this->assertCount(3, $messages);
            foreach ($messages as $message) {
                $this->assertStringContainsString('lang="'.$locale.'"', $message);
                $this->assertStringContainsString('text/html', $message);
                $this->assertStringContainsString('text/plain', $message);
                $this->assertStringNotContainsString('QA-NOT-REAL', $message);
                $this->assertStringNotContainsString('Shënim privat', $message);
            }
            $this->assertStringContainsString(['sq' => 'skadon pas 60 minutash', 'en' => 'This link expires in 60 minutes', 'mk' => 'истекува за 60 минути'][$locale], $messages[0]);
            $this->assertStringContainsString('locale='.$locale, $messages[0]);
            preg_match('~/reset-password/([a-zA-Z0-9]+)~', $messages[0], $matches);
            $this->assertTrue(Password::tokenExists($owner, $matches[1]));
            $this->assertStringContainsString(['sq' => 'Pagesa për qasjen publike u miratua', 'en' => 'Your public access payment was approved', 'mk' => 'Твоето плаќање за јавен пристап е одобрено'][$locale], $messages[1]);
            $this->assertStringContainsString(['sq' => 'Njoftimi i pagesës u refuzua', 'en' => 'Your payment submission was rejected', 'mk' => 'Пријавата за твоето плаќање е одбиена'][$locale], $messages[2]);
            $this->assertDatabaseCount('subscriptions', 1);
        } finally {
            Mail::purge('smtp');
            $sink->stop();
        }
    }
}
