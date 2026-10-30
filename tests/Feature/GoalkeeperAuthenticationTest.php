<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\GoalkeeperContact;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoalkeeperAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        config(['transactional-mail.enabled' => true]);
        Notification::fake();
    }

    private function registration(array $changes = []): array
    {
        $city = City::where('name', 'Prishtinë')->firstOrFail();

        return array_replace([
            'name' => 'Test Keeper', 'email' => 'keeper@example.test',
            'password' => 'Keeper-Test-12345', 'password_confirmation' => 'Keeper-Test-12345',
            'accept_terms' => true,
            'phone' => '+383 44 123 456', 'country_id' => $city->country_id, 'city_id' => $city->id,
            'bio' => 'A test goalkeeper profile.', 'price' => '7.25', 'formats' => ['5v5', '6v6'],
        ], $changes);
    }

    private function ownedProfile(User $user): GoalkeeperProfile
    {
        $profile = GoalkeeperProfile::firstOrFail()->replicate();
        $profile->slug = 'owned-'.$user->id;
        $profile->name = $user->name;
        $user->goalkeeperProfile()->save($profile);
        $profile->contact()->create(['phone' => '+38344123456', 'whatsapp' => '38344123456']);

        return $profile;
    }

    public function test_registration_persists_hashed_account_and_owned_profile(): void
    {
        $this->get('/register');
        $oldSession = session()->getId();
        $this->post('/register', $this->registration(['email' => '  KEEPER@EXAMPLE.TEST  ']))->assertRedirect('/dashboard');
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertSame('keeper@example.test', $user->email);
        $this->assertNotSame('Keeper-Test-12345', $user->getAuthPassword());
        $this->assertTrue(Hash::check('Keeper-Test-12345', $user->getAuthPassword()));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
        $profile = $user->goalkeeperProfile;
        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame(725, $profile->price_cents);
        $this->assertSame('+38344123456', $profile->contact->phone);
        $this->assertSame('38344123456', $profile->contact->whatsapp);
        $this->assertSame(['5v5', '6v6'], $profile->matchFormats->pluck('code')->all());
        $this->assertFalse($profile->is_verified);
        $this->assertSame(4, GoalkeeperProfile::whereNull('user_id')->count());
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('keeper.id', $profile->id)->where('auth.user.email', $user->email)
            ->missing('auth.user.password')->missing('auth.user.remember_token')->missing('keeper.user_id'));
    }

    public static function invalidRegistrations(): array
    {
        return [
            [['name' => ''], 'name'], [['email' => 'not-an-email'], 'email'],
            [['email' => ['bad']], 'email'], [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            [['password_confirmation' => 'mismatch'], 'password'],
            [['password' => ['bad'], 'password_confirmation' => ['bad']], 'password'],
            [['password' => 'Aa1'.str_repeat('界', 24), 'password_confirmation' => 'Aa1'.str_repeat('界', 24)], 'password'],
            [['phone' => '123'], 'phone'], [['city_id' => 999], 'city_id'],
            [['country_id' => 999], 'country_id'], [['country_id' => [1]], 'country_id'],
            [['price' => '-1'], 'price'], [['price' => '7.255'], 'price'],
            [['formats' => []], 'formats'], [['formats' => ['11v11']], 'formats.0'], [['accept_terms' => false], 'accept_terms'],
            [['user_id' => 10], 'user_id'], [['is_verified' => true], 'is_verified'],
        ];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_registration_rejects_invalid_input(array $changes, string $error): void
    {
        $this->postJson('/register', $this->registration($changes))->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('goalkeeper_profiles', 4);
        $this->assertGuest();
    }

    public function test_registration_rejects_duplicate_email_and_wrong_country_city_pair(): void
    {
        User::factory()->create(['email' => 'keeper@example.test']);
        $this->postJson('/register', $this->registration())->assertJsonValidationErrors('email');
        $city = City::where('name', 'Tiranë')->firstOrFail();
        $this->postJson('/register', $this->registration(['email' => 'other@example.test', 'city_id' => $city->id]))
            ->assertJsonValidationErrors('city_id');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_accepts_valid_photos_and_rejects_non_images(): void
    {
        Storage::fake('public');
        $this->post('/register', $this->registration(['photo' => UploadedFile::fake()->image('keeper.jpg')]))
            ->assertRedirect('/dashboard');
        $this->assertCount(1, Storage::disk('public')->allFiles('keeper-photos'));
        $this->assertStringContainsString('/storage/keeper-photos/', User::sole()->goalkeeperProfile->photo_url);
        $this->post('/logout');
        $this->postJson('/register', $this->registration(['email' => 'second@example.test', 'photo' => UploadedFile::fake()->create('payload.svg', 1, 'image/svg+xml')]))
            ->assertJsonValidationErrors('photo');
    }

    public function test_failed_profile_creation_rolls_back_account_and_uploaded_photo(): void
    {
        Storage::fake('public');
        GoalkeeperContact::creating(fn () => throw new RuntimeException('Simulated write failure'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/register', $this->registration(['photo' => UploadedFile::fake()->image('keeper.jpg')]));
            $this->fail('Expected the simulated write failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated write failure', $e->getMessage());
        } finally {
            GoalkeeperContact::flushEventListeners();
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('goalkeeper_profiles', 4);
        $this->assertCount(0, Storage::disk('public')->allFiles('keeper-photos'));
        $this->assertGuest();
    }

    public function test_valid_login_rotates_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['password' => 'Keeper-Test-12345']);
        $this->ownedProfile($user);
        $this->get('/login');
        $oldSession = session()->getId();
        $this->post('/login', ['email' => mb_strtoupper($user->email), 'password' => 'Keeper-Test-12345', 'remember' => true])
            ->assertRedirect('/dashboard')->assertCookie(Auth::guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->get('/dashboard')->assertOk();
        $token = session()->token();
        $session = session()->getId();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->assertNotSame($session, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_wrong_password_is_generic_and_login_is_throttled(): void
    {
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_guest_routes_redirect_signed_in_goalkeepers_to_dashboard(): void
    {
        $user = User::factory()->create();
        $this->ownedProfile($user);
        $this->actingAs($user)->get('/login')->assertRedirect('/dashboard');
        $this->get('/register')->assertRedirect('/dashboard');
    }

    public function test_dashboard_only_returns_the_authenticated_keepers_profile(): void
    {
        $first = User::factory()->create();
        $mine = $this->ownedProfile($first);
        $other = User::factory()->create();
        $otherProfile = $this->ownedProfile($other);
        $this->actingAs($first)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('keeper.id', $mine->id)->where('keeper.name', $first->name)->missing('keepers'));
        $this->get('/dashboard?keeper='.$otherProfile->id.'&user_id='.$other->id)
            ->assertInertia(fn (Assert $page) => $page->where('keeper.id', $mine->id));
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();
    }

    public function test_owners_can_update_only_their_own_profile(): void
    {
        $user = User::factory()->create();
        $mine = $this->ownedProfile($user);
        $other = $this->ownedProfile(User::factory()->create());
        $data = array_intersect_key($this->registration(), array_flip(['name', 'phone', 'city_id', 'bio', 'price', 'formats']));
        $data['name'] = 'Updated Owner';
        $data += ['experience_years' => 3, 'languages' => ['Shqip'], 'whatsapp' => '+38344123456'];
        $this->actingAs($user)->patch("/keepers/{$other->id}", $data)->assertForbidden();
        $this->patch('/keepers/1', $data)->assertForbidden();
        $this->patch("/keepers/{$mine->id}", $data)->assertRedirect('/dashboard');
        $this->assertSame('Updated Owner', $mine->fresh()->name);
        $this->assertSame('Updated Owner', $user->fresh()->name);
        $this->assertSame(725, $mine->fresh()->price_cents);
        $this->assertSame($user->id, $mine->fresh()->user_id);
        $this->patchJson("/keepers/{$mine->id}", [...$data, 'user_id' => $other->user_id])->assertJsonValidationErrors('user_id');
        $this->patchJson("/keepers/{$mine->id}", [...$data, 'price' => '-1'])->assertJsonValidationErrors('price');
    }

    public function test_public_browsing_profiles_and_contact_details_require_no_account(): void
    {
        $profile = GoalkeeperProfile::findOrFail(1);
        $profile->user()->associate(User::factory()->create());
        $profile->save();
        $this->get('/browse')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
        $this->get('/keepers/1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('keeper.phone', '38344111222')->missing('keeper.email')->missing('keeper.user_id'));
        $this->patchJson('/keepers/1', [])->assertUnauthorized();
        $this->get('/logout')->assertStatus(405);
        $this->assertGuest();
    }

    public function test_password_reset_is_generic_for_unknown_emails_and_sends_for_known_users(): void
    {
        $user = User::factory()->create();
        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $known->assertRedirect('/forgot-password')->assertSessionHas('status');
        $status = session('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('status', $status);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_password_reset_link_uses_configured_origin_instead_of_request_host(): void
    {
        config(['app.url' => 'https://keepers.example.test']);
        $user = User::factory()->create();

        $this->post('http://untrusted.example.test/forgot-password', ['email' => $user->email])
            ->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertSame('keepers.example.test', parse_url($url, PHP_URL_HOST));
            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
            $this->assertStringStartsWith('/reset-password/', parse_url($url, PHP_URL_PATH));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($user->email, $query['email']);

            return true;
        });
    }

    public function test_valid_reset_changes_hash_consumes_token_and_rejects_reuse(): void
    {
        $user = User::factory()->create(['password' => 'Old-Keeper-12345']);
        $token = Password::createToken($user);
        $this->assertNotSame($token, DB::table('password_reset_tokens')->sole()->token);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'New-Keeper-12345', 'password_confirmation' => 'New-Keeper-12345'];
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->assertTrue(Hash::check('New-Keeper-12345', $user->fresh()->password));
        $this->assertFalse(Hash::check('Old-Keeper-12345', $user->fresh()->password));
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/reset-password', $data)->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_reset_rejects_invalid_expired_and_unconfirmed_tokens(): void
    {
        $user = User::factory()->create();
        $data = ['email' => $user->email, 'token' => 'invalid', 'password' => 'New-Keeper-12345', 'password_confirmation' => 'New-Keeper-12345'];
        $oldHash = $user->password;
        $this->postJson('/reset-password', $data)->assertJsonValidationErrors('email');
        $data['token'] = Password::createToken($user);
        $data['password_confirmation'] = 'mismatch';
        $this->postJson('/reset-password', $data)->assertJsonValidationErrors('password')
            ->assertJsonPath('errors.password.0', 'Konfirmimi i fjalëkalimit nuk përputhet.');
        $data['password_confirmation'] = $data['password'];
        DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(61)]);
        $this->postJson('/reset-password', $data)->assertJsonValidationErrors('email');
        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_password_hash_change_invalidates_an_existing_protected_session(): void
    {
        $user = User::factory()->create();
        $this->ownedProfile($user);
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->forceFill(['password' => 'Changed-Keeper-12345'])->save();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_authentication_endpoints_keep_csrf_protection(): void
    {
        // Test only: leave the unit-test CSRF bypass, while retaining isolated DB config.
        $this->app->instance('env', 'local');
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => 'test@example.test', 'password' => 'anything'])->assertStatus(419);
        $this->post('/register', $this->registration())->assertStatus(419);
    }

    public function test_private_pages_encrypt_history_and_logout_clears_the_history_key(): void
    {
        $user = User::factory()->create();
        $this->ownedProfile($user);
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertTrue($response->viewData('page')['encryptHistory']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->post('/logout')->assertRedirect('/');
        $this->assertTrue($this->get('/')->viewData('page')['clearHistory']);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_owner_migration_is_additive_and_reversible_for_unclaimed_profiles(): void
    {
        $this->assertSame(4, GoalkeeperProfile::whereNull('user_id')->count());
        $migration = require database_path('migrations/2026_10_20_000001_add_owner_to_goalkeeper_profiles.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('goalkeeper_profiles', 'user_id'));
        $this->assertDatabaseCount('goalkeeper_profiles', 4);
        $migration->up();
        $this->assertSame(4, GoalkeeperProfile::whereNull('user_id')->count());
    }
}
