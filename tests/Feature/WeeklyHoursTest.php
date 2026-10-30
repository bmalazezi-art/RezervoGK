<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\GoalkeeperWeeklyHour;
use App\Models\User;
use App\Support\AvailabilityCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WeeklyHoursTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoalkeeperProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        $this->owner = User::factory()->create();
        $this->profile = GoalkeeperProfile::firstOrFail();
        $this->profile->user()->associate($this->owner)->save();
    }

    private function url(): string
    {
        return '/keepers/'.$this->profile->id.'/weekly-hours';
    }

    public function test_owner_can_save_replace_and_clear_weekly_hours_without_changing_dated_availability(): void
    {
        $dates = DB::table('goalkeeper_availabilities')->orderBy('id')->get()->toJson();
        $profileBefore = $this->profile->getRawOriginal();
        $this->actingAs($this->owner)->put($this->url(), ['days' => [
            ['weekday' => 7, 'start' => '13:00', 'end' => '02:00'],
            ['weekday' => 1, 'start' => '18:00', 'end' => '00:00'],
            ['weekday' => 3, 'start' => '00:00', 'end' => '23:59'],
        ]])->assertRedirect('/dashboard');
        $this->get('/keepers/'.$this->profile->id)->assertInertia(fn (Assert $page) => $page
            ->has('keeper.weeklyHours', 3)->where('keeper.weeklyHours.0', ['weekday' => 1, 'start' => '18:00', 'end' => '00:00', 'endsNextDay' => false])
            ->where('keeper.weeklyHours.1.endsNextDay', false)->where('keeper.weeklyHours.2.weekday', 7)->where('keeper.weeklyHours.2.endsNextDay', true));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->has('keeper.weeklyHours', 3));
        $this->put($this->url(), ['days' => [['weekday' => 6, 'start' => '13:00', 'end' => '02:00']]])->assertRedirect('/dashboard');
        $this->assertDatabaseCount('goalkeeper_weekly_hours', 1);
        $this->assertSame(6, $this->profile->weeklyHours()->sole()->weekday);
        $this->put($this->url(), ['days' => []])->assertRedirect('/dashboard');
        $this->assertDatabaseCount('goalkeeper_weekly_hours', 0);
        $this->assertSame($dates, DB::table('goalkeeper_availabilities')->orderBy('id')->get()->toJson());
        $this->assertSame($profileBefore, $this->profile->fresh()->getRawOriginal());
    }

    public function test_weekly_hours_repeat_in_search_and_date_exceptions_override_them(): void
    {
        $today = AvailabilityCalendar::today()->toDateString();
        $this->profile->availabilities()->delete();
        $this->actingAs($this->owner)->put($this->url(), ['days' => [
            ['weekday' => AvailabilityCalendar::today()->dayOfWeekIso, 'start' => '00:00', 'end' => '23:59'],
        ]])->assertRedirect('/dashboard');
        $this->get('/browse?date='.$today)->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 1)->where('keepers.0.id', $this->profile->id));
        $this->post('/keepers/'.$this->profile->id.'/availability', ['date' => $today, 'available' => false])->assertRedirect('/dashboard');
        $this->get('/browse?date='.$today)->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 1)->where('keepers.0.id', $this->profile->id)->where('keepers.0.availableOnRequestedDate', false));
        $this->post('/keepers/'.$this->profile->id.'/availability', ['date' => $today, 'available' => true])->assertRedirect('/dashboard');
        $this->put($this->url(), ['days' => []])->assertRedirect('/dashboard');
        $this->get('/browse?date='.$today.'&max_price=10&format=5v5')->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 1)->where('keepers.0.id', $this->profile->id)->where('keepers.0.weeklyHours', []));
    }

    public function test_combined_schedule_and_date_exceptions_save_reload_and_resolve_the_next_30_days(): void
    {
        CarbonImmutable::setTestNow('2026-10-27 10:00:00');
        try {
            $this->profile->availabilities()->delete();
            $this->profile->availabilityExceptions()->delete();
            $offDate = '2026-10-31';
            $extraDate = '2026-11-04';
            $this->actingAs($this->owner)->put($this->url(), ['days' => [
                ['weekday' => 1, 'start' => '18:00', 'end' => '23:00'],
                ['weekday' => 2, 'start' => '20:00', 'end' => '23:00'],
                ['weekday' => 4, 'start' => '18:00', 'end' => '23:00'],
                ['weekday' => 5, 'start' => '18:00', 'end' => '23:00'],
                ['weekday' => 6, 'start' => '18:00', 'end' => '23:00'],
                ['weekday' => 7, 'start' => '18:00', 'end' => '23:00'],
            ], 'offDates' => [$offDate], 'extraDates' => [$extraDate]])->assertRedirect('/dashboard');

            $this->assertDatabaseCount('goalkeeper_weekly_hours', 6);
            $this->assertDatabaseHas('goalkeeper_weekly_hours', ['goalkeeper_profile_id' => $this->profile->id, 'weekday' => 2, 'start_time' => '20:00', 'end_time' => '23:00']);
            $this->assertDatabaseHas('goalkeeper_availabilities', ['goalkeeper_profile_id' => $this->profile->id, 'available_on' => $extraDate]);
            $this->assertDatabaseHas('goalkeeper_availability_exceptions', ['goalkeeper_profile_id' => $this->profile->id, 'unavailable_on' => $offDate]);

            $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
                ->has('keeper.weeklyHours', 6)
                ->where('extraDates', [$extraDate])
                ->where('offDates', [$offDate]));

            $schedule = $this->profile->fresh()->weeklyHours->mapWithKeys(fn ($hour) => [$hour->weekday => ['start' => substr($hour->start_time, 0, 5), 'end' => substr($hour->end_time, 0, 5)]] )->all();
            $this->assertSame('day_off', AvailabilityCalendar::resolveDate($offDate, $schedule, [$offDate], [$offDate])['kind']);
            $this->assertSame('extra', AvailabilityCalendar::resolveDate($extraDate, $schedule, [$extraDate], [$offDate])['kind']);
            $this->assertSame('off', AvailabilityCalendar::resolveDate('2026-10-28', $schedule, [$offDate], ['2026-10-28'])['kind']);
            $this->assertCount(25, AvailabilityCalendar::upcomingDates($this->profile->fresh()));

            $this->get('/browse?date='.$offDate)->assertInertia(fn (Assert $page) => $page
                ->has('keepers', 1)->where('keepers.0.id', $this->profile->id)->where('keepers.0.availableOnRequestedDate', false));
            $this->get('/browse?date='.$extraDate)->assertInertia(fn (Assert $page) => $page
                ->has('keepers', 1)->where('keepers.0.id', $this->profile->id)->where('keepers.0.availableOnRequestedDate', true));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_guests_other_owners_admins_and_unclaimed_profiles_cannot_be_updated(): void
    {
        $this->putJson($this->url(), ['days' => []])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->putJson($this->url(), ['days' => []])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->putJson($this->url(), ['days' => []])->assertForbidden();
        $this->actingAs($this->owner)->putJson('/keepers/2/weekly-hours', ['days' => []])->assertForbidden();
        $this->assertDatabaseCount('goalkeeper_weekly_hours', 0);
    }

    public static function invalidSchedules(): array
    {
        $day = ['weekday' => 1, 'start' => '18:00', 'end' => '00:00'];

        return [
            [[], 'days'], [['days' => 'bad'], 'days'], [['days' => ['monday' => $day]], 'days'],
            [['days' => array_fill(0, 8, $day)], 'days'], [['days' => [$day, $day]], 'days.0.weekday'],
            [['days' => [array_replace($day, ['weekday' => 0])]], 'days.0.weekday'],
            [['days' => [array_replace($day, ['weekday' => 8])]], 'days.0.weekday'],
            [['days' => [array_replace($day, ['weekday' => 1.5])]], 'days.0.weekday'],
            [['days' => [array_replace($day, ['start' => '24:00'])]], 'days.0.start'],
            [['days' => [array_replace($day, ['end' => '24:00'])]], 'days.0.end'],
            [['days' => [array_replace($day, ['start' => '18:00:00'])]], 'days.0.start'],
            [['days' => [array_replace($day, ['end' => '18:00'])]], 'days.0.end'],
            [['days' => [array_replace($day, ['start' => '00:00'])]], 'days.0.end'],
            [['days' => [['weekday' => 1]]], 'days.0.start'],
            [['days' => [array_replace($day, ['goalkeeper_profile_id' => 2])]], 'days.0'],
            [['days' => [$day], 'user_id' => 2], 'user_id'],
        ];
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_input_preserves_the_existing_schedule(array $data, string $error): void
    {
        $this->profile->weeklyHours()->create(['weekday' => 6, 'start_time' => '13:00', 'end_time' => '02:00']);
        $before = $this->profile->weeklyHours()->get()->toJson();
        $this->actingAs($this->owner)->putJson($this->url(), $data)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame($before, $this->profile->weeklyHours()->get()->toJson());
    }

    public function test_replacement_is_atomic_if_an_insert_fails(): void
    {
        $this->profile->weeklyHours()->create(['weekday' => 6, 'start_time' => '13:00', 'end_time' => '02:00']);
        $before = $this->profile->weeklyHours()->get()->toJson();
        GoalkeeperWeeklyHour::creating(fn () => throw new RuntimeException('Simulated weekly write failure'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->owner)->put($this->url(), ['days' => [['weekday' => 1, 'start' => '18:00', 'end' => '00:00']]]);
            $this->fail('Expected simulated write failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated weekly write failure', $e->getMessage());
        } finally {
            GoalkeeperWeeklyHour::flushEventListeners();
        }
        $this->assertSame($before, $this->profile->weeklyHours()->get()->toJson());
    }

    public function test_expired_owner_can_edit_and_weekly_hours_do_not_publish_the_profile(): void
    {
        $this->owner->forceFill(['trial_started_at' => now()->subDays(31), 'trial_ends_at' => now()->subDay()])->save();
        $this->actingAs($this->owner)->put($this->url(), ['days' => [['weekday' => 1, 'start' => '18:00', 'end' => '00:00']]])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->has('keeper.weeklyHours', 1));
        $this->get('/keepers/'.$this->profile->id)->assertOk();
        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->has('keepers', 1));
    }
}
