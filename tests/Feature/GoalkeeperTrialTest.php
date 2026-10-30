<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use App\Support\AvailabilityCalendar;
use App\Support\GoalkeeperTrial;
use Carbon\CarbonImmutable;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoalkeeperTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise preserved paid-mode code only in this isolated test process.
        config(['marketplace.free_launch' => false]);
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
        $this->seed(GoalkeeperDemoSeeder::class);
    }

    private function owner(): User
    {
        $user = User::factory()->create(['password' => 'Trial-Test-12345!']);
        $profile = GoalkeeperProfile::firstOrFail();
        $profile->user()->associate($user);
        $profile->save();
        $profile->availabilities()->create(['available_on' => '2026-04-14']);

        return $user;
    }

    private function profileData(): array
    {
        return ['name' => 'Edited after expiry', 'city_id' => City::firstOrFail()->id, 'bio' => 'Still editable',
            'price' => '7.25', 'phone' => '+38344123456', 'whatsapp' => '+38344123456',
            'experience_years' => 4, 'languages' => ['Shqip'], 'formats' => ['5v5']];
    }

    public function test_registration_starts_one_server_owned_thirty_day_trial_atomically(): void
    {
        $city = City::firstOrFail();
        $data = [...$this->profileData(), 'email' => 'trial-registration@example.test', 'country_id' => $city->country_id,
            'password' => 'Trial-Test-12345!', 'password_confirmation' => 'Trial-Test-12345!', 'accept_terms' => true];
        $this->post('/register', $data)->assertRedirect('/dashboard');
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('2026-03-15T12:00:00+00:00', $user->trial_started_at->toIso8601String());
        $this->assertSame('2026-04-14T12:00:00+00:00', $user->trial_ends_at->toIso8601String());
        $this->assertEquals(GoalkeeperTrial::SECONDS, $user->trial_started_at->diffInSeconds($user->trial_ends_at));
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('trial.remainingDays', 30)->where('trial.isEligible', true));
        $original = $user->trial_ends_at->toIso8601String();
        $this->travelTo($user->trial_ends_at->addDay());
        GoalkeeperTrial::start($user);
        $this->assertSame($original, $user->fresh()->trial_ends_at->toIso8601String());
        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', ['email' => $user->email, 'password' => $data['password']])->assertRedirect('/dashboard');
        $this->assertSame($original, $user->fresh()->trial_ends_at->toIso8601String());
    }

    public static function boundaries(): array
    {
        return [
            'before start' => ['2026-03-15 11:59:59.999999', false, 0],
            'at start' => ['2026-03-15 12:00:00', true, 30],
            'one second later' => ['2026-03-15 12:00:01', true, 30],
            'one full day elapsed' => ['2026-03-16 12:00:00', true, 29],
            'one day remains' => ['2026-04-13 12:00:00', true, 1],
            'one microsecond before expiry' => ['2026-04-14 11:59:59.999999', true, 1],
            'exact expiry' => ['2026-04-14 12:00:00', false, 0],
            'one microsecond after expiry' => ['2026-04-14 12:00:00.000001', false, 0],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_all_public_entry_points_and_dashboard_agree_at_boundaries(string $time, bool $active, int $days): void
    {
        $user = $this->owner();
        $profile = $user->goalkeeperProfile;
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
        $profile->availabilities()->delete();
        $requestedDate = AvailabilityCalendar::maxDate()->toDateString();
        $profile->availabilities()->create(['available_on' => $requestedDate]);
        $this->get('/')->assertInertia(fn (Assert $p) => $p->has('keepers', $active ? 1 : 0));
        $this->get('/browse')->assertInertia(fn (Assert $p) => $p->has('keepers', $active ? 1 : 0));
        $this->get('/browse?date='.$requestedDate.'&format=5v5&max_price=10&city_id='.$profile->city_id)
            ->assertInertia(fn (Assert $p) => $p->has('keepers', $active ? 1 : 0)
                ->when($active, fn (Assert $p) => $p->where('keepers.0.availableOnRequestedDate', true)));
        $this->get('/keepers/'.$profile->id)->assertStatus($active ? 200 : 404);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $p) => $p
            ->where('trial.isEligible', $active)->where('trial.remainingDays', $days));
        $this->get('/keepers/'.$profile->id)->assertStatus($active ? 200 : 404);
    }

    public function test_expired_owner_can_login_edit_profile_and_availability_without_renewal(): void
    {
        $user = $this->owner();
        $end = $user->trial_ends_at->toIso8601String();
        $profile = $user->goalkeeperProfile;
        $this->travelTo($user->trial_ends_at);
        $this->post('/login', ['email' => $user->email, 'password' => 'Trial-Test-12345!'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('trial.status', 'expired')->where('trial.remainingDays', 0));
        $this->patch('/keepers/'.$profile->id, $this->profileData())->assertRedirect('/dashboard');
        $this->post('/keepers/'.$profile->id.'/availability', ['date' => '2026-04-20'])->assertRedirect('/dashboard');
        $this->delete('/keepers/'.$profile->id.'/availability/2026-04-20')->assertRedirect('/dashboard');
        $this->assertSame('Edited after expiry', $profile->fresh()->name);
        $this->assertSame($end, $user->fresh()->trial_ends_at->toIso8601String());
        $this->get('/keepers/'.$profile->id)->assertNotFound();
    }

    public function test_trial_fields_cannot_be_set_by_registration_or_profile_requests(): void
    {
        $user = $this->owner();
        $original = $user->trial_ends_at->toIso8601String();
        $this->actingAs($user)->patchJson('/keepers/'.$user->goalkeeperProfile->id,
            [...$this->profileData(), 'trial_started_at' => '2099-01-01', 'trial_ends_at' => '2099-02-01'])
            ->assertJsonValidationErrors(['trial_started_at', 'trial_ends_at']);
        $this->assertSame($original, $user->fresh()->trial_ends_at->toIso8601String());
        $this->post('/logout');
        $city = City::firstOrFail();
        $this->postJson('/register', [...$this->profileData(), 'country_id' => $city->country_id,
            'email' => 'spoof@example.test', 'password' => 'Trial-Test-12345!', 'password_confirmation' => 'Trial-Test-12345!',
            'trial_ends_at' => '2099-02-01'])->assertJsonValidationErrors('trial_ends_at');
        $this->assertDatabaseMissing('users', ['email' => 'spoof@example.test']);
    }

    public function test_unclaimed_and_missing_or_partial_trials_fail_closed_without_data_loss(): void
    {
        $this->get('/browse')->assertInertia(fn (Assert $p) => $p->has('keepers', 0));
        $this->get('/keepers/1')->assertNotFound();
        $this->assertDatabaseCount('goalkeeper_profiles', 4);
        $user = $this->owner();
        foreach ([[null, null], [null, $user->trial_ends_at], [$user->trial_started_at, null]] as [$start, $end]) {
            $user->forceFill(['trial_started_at' => $start, 'trial_ends_at' => $end])->save();
            $this->get('/keepers/'.$user->goalkeeperProfile->id)->assertNotFound();
            $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('trial.isEligible', false)->where('trial.remainingDays', 0));
        }
    }

    public function test_additive_migration_anchors_existing_trials_to_registration_without_resetting_other_data(): void
    {
        $migration = require database_path('migrations/2026_10_21_000001_add_goalkeeper_trial_to_users.php');
        $user = $this->owner();
        $original = $user->only(['id', 'email', 'password', 'updated_at']);
        $migration->down();
        DB::table('users')->where('id', $user->id)->update(['created_at' => '2025-01-01 10:00:00']);
        $migration->up();
        $user->refresh();
        $this->assertEquals($original, $user->only(array_keys($original)));
        $this->assertSame('2025-01-01 10:00:00', $user->trial_started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2025-01-31 10:00:00', $user->trial_ends_at->format('Y-m-d H:i:s'));
        $this->assertFalse(GoalkeeperTrial::status($user)['isEligible']);
        $this->assertDatabaseCount('goalkeeper_profiles', 4);
        $this->assertSame($user->id, GoalkeeperProfile::firstOrFail()->user_id);
    }
}
