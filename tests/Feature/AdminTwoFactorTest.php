<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AdminLoginCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        config([
            'admin-security.email_code' => true,
            'transactional-mail.enabled' => true, 'mail.default' => 'array',
            'mail.from.address' => 'no-reply@gjejportier.online', 'app.url' => 'https://gjejportier.online',
        ]);
        $this->admin = User::factory()->create(['email' => 'gjejportier@gmail.com', 'is_admin' => true, 'password' => 'Admin-Password-123']);
    }

    private function signIn(): string
    {
        $this->get('/admin/login');
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'Admin-Password-123'])->assertRedirect('/admin/verify');
        $this->assertGuest();
        $code = null;
        Notification::assertSentTo($this->admin, AdminLoginCode::class, function (AdminLoginCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    public function test_admin_is_only_signed_in_after_the_emailed_code(): void
    {
        $code = $this->signIn();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->get('/admin/verify')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminVerify')->where('email', 'g••••••@gmail.com'));
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->post('/admin/verify', ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($this->admin);
        $this->get('/admin')->assertOk();
    }

    public function test_the_code_is_not_in_the_subject_and_session_keeps_only_a_hash(): void
    {
        $code = $this->signIn();
        Notification::assertSentTo($this->admin, AdminLoginCode::class, fn (AdminLoginCode $n) => ! str_contains($n->toMail($this->admin)->subject, $code));
        $this->assertStringNotContainsString($code, json_encode(session()->all()));
    }

    public function test_wrong_codes_lock_the_challenge_after_five_attempts(): void
    {
        $code = $this->signIn();
        $wrong = $code === '111111' ? '222222' : '111111';
        foreach (range(1, 4) as $attempt) {
            $this->post('/admin/verify', ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->post('/admin/verify', ['code' => $wrong])->assertRedirect('/admin/login');
        $this->post('/admin/verify', ['code' => $code])->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_expired_codes_do_not_work(): void
    {
        $code = $this->signIn();
        $this->travel(11)->minutes();
        $this->post('/admin/verify', ['code' => $code])->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_resend_waits_a_minute_and_replaces_the_old_code(): void
    {
        $first = $this->signIn();
        $this->post('/admin/verify/resend')->assertSessionHasErrors('code');
        $this->travel(61)->seconds();
        $this->post('/admin/verify/resend')->assertSessionHas('status');
        Notification::assertSentToTimes($this->admin, AdminLoginCode::class, 2);
        $codes = [];
        Notification::assertSentTo($this->admin, AdminLoginCode::class, function (AdminLoginCode $n) use (&$codes) {
            $codes[] = $n->code;

            return true;
        });
        $second = end($codes);
        if ($first !== $second) {
            $this->post('/admin/verify', ['code' => $first])->assertSessionHasErrors('code');
        }
        $this->post('/admin/verify', ['code' => $second])->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_cannot_sign_in_when_the_code_cannot_be_sent(): void
    {
        config(['transactional-mail.enabled' => false]);
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'Admin-Password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_goalkeepers_sign_in_without_a_code(): void
    {
        $keeper = User::factory()->create(['is_admin' => false, 'password' => 'Keeper-Password-123']);
        $this->post('/login', ['email' => $keeper->email, 'password' => 'Keeper-Password-123']);
        $this->assertAuthenticatedAs($keeper);
        Notification::assertNothingSentTo($keeper);
    }
}
