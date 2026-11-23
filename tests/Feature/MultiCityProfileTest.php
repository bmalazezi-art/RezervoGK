<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use App\Support\AvailabilityCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MultiCityProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoalkeeperProfile $profile;

    private City $prishtine;

    private City $prizren;

    private City $gjilan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        $this->prishtine = City::where('name', 'Prishtinë')->firstOrFail();
        $this->prizren = City::where('name', 'Prizren')->firstOrFail();
        $this->gjilan = City::where('name', 'Gjilan')->firstOrFail();
        $this->owner = User::factory()->create();
        $this->profile = $this->owner->goalkeeperProfile()->create([
            'name' => 'Multi City Keeper', 'slug' => 'multi-city-keeper', 'city_id' => $this->prishtine->id,
            'bio' => 'Plays in Prishtinë and Prizren.', 'price_cents' => 1000, 'currency' => 'EUR',
            'photo_url' => '/keeper-placeholder.svg', 'languages' => ['Shqip'],
        ]);
        $this->profile->contact()->create(['phone' => '+38344123456', 'whatsapp' => '38344123456']);
    }

    private function profileData(array $changes = []): array
    {
        return array_replace([
            'name' => 'Multi City Keeper', 'city_id' => $this->prishtine->id, 'bio' => 'Plays in Prishtinë and Prizren.',
            'price' => '10', 'phone' => '+38344123456', 'whatsapp' => '+38344123456',
            'experience_years' => 5, 'languages' => ['Shqip'], 'formats' => ['5v5'],
        ], $changes);
    }

    private function registration(array $changes = []): array
    {
        return array_replace([
            'name' => 'New Keeper', 'email' => 'new-keeper@example.test',
            'password' => 'Keeper-Test-12345', 'password_confirmation' => 'Keeper-Test-12345', 'accept_terms' => true,
            'phone' => '+383 44 123 456', 'country_id' => $this->prishtine->country_id, 'city_id' => $this->prishtine->id,
            'bio' => 'A test goalkeeper profile.', 'price' => '7.25', 'formats' => ['5v5'],
        ], $changes);
    }

    /** The next date (today included) that falls on the given ISO weekday. */
    private function nextWeekday(int $weekday): string
    {
        $day = AvailabilityCalendar::today();
        while ($day->dayOfWeekIso !== $weekday) {
            $day = $day->addDay();
        }

        return $day->toDateString();
    }

    /** @return array<int, array<string, mixed>> keyed by profile id */
    private function browseKeepers(string $query): array
    {
        $props = $this->get('/browse?'.$query)->assertOk()->viewData('page')['props'];

        return collect($props['keepers'])->keyBy('id')->all();
    }

    private function saveWeeklyHours(array $payload): TestResponse
    {
        return $this->actingAs($this->owner)->put('/keepers/'.$this->profile->id.'/weekly-hours', $payload);
    }

    public function test_registration_saves_extra_cities_in_the_same_country_only(): void
    {
        $tirane = City::where('name', 'Tiranë')->firstOrFail();
        $this->post('/register', $this->registration(['extra_city_ids' => [$tirane->id]]))->assertSessionHasErrors('extra_city_ids.0');
        $this->assertDatabaseMissing('users', ['email' => 'new-keeper@example.test']);

        $this->post('/register', $this->registration(['extra_city_ids' => [$this->prizren->id, $this->gjilan->id, $this->prishtine->id]]))
            ->assertRedirect('/dashboard');
        $profile = User::where('email', 'new-keeper@example.test')->sole()->goalkeeperProfile;
        $this->assertSame($this->prishtine->id, $profile->city_id);
        // The primary city is never stored twice.
        $this->assertEqualsCanonicalizing([$this->prizren->id, $this->gjilan->id], $profile->additionalCities->pluck('id')->all());
        $this->assertSame([$this->prishtine->id, $this->gjilan->id, $this->prizren->id], $profile->serviceCityIds());
    }

    public function test_registration_limits_the_number_of_extra_cities(): void
    {
        $five = City::where('country_id', $this->prishtine->country_id)->whereKeyNot($this->prishtine->id)->limit(5)->pluck('id')->all();
        $this->assertCount(5, $five);
        $this->post('/register', $this->registration(['extra_city_ids' => $five]))->assertSessionHasErrors('extra_city_ids');
        $this->post('/register', $this->registration(['extra_city_ids' => array_slice($five, 0, 4)]))->assertRedirect('/dashboard');
    }

    public function test_dashboard_saves_and_clears_extra_cities_and_moves_orphaned_days_to_the_primary_city(): void
    {
        $this->actingAs($this->owner)->patch('/keepers/'.$this->profile->id, $this->profileData(['extra_city_ids' => [$this->prizren->id, $this->gjilan->id]]))
            ->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->prizren->id, $this->gjilan->id], $this->profile->additionalCities()->pluck('cities.id')->all());
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('extraCityIds', [$this->gjilan->id, $this->prizren->id])
            ->where('keeper.cities', [
                ['id' => $this->prishtine->id, 'name' => 'Prishtinë'], ['id' => $this->gjilan->id, 'name' => 'Gjilan'], ['id' => $this->prizren->id, 'name' => 'Prizren'],
            ]));

        $this->saveWeeklyHours(['days' => [
            ['weekday' => 1, 'start' => '18:00', 'end' => '23:00', 'city_id' => $this->prizren->id],
            ['weekday' => 6, 'start' => '12:00', 'end' => '16:00', 'city_id' => $this->gjilan->id],
        ]])->assertRedirect('/dashboard')->assertSessionHasNoErrors();

        // Leaving Gjilan out: the Saturday entry falls back to the primary city.
        $this->patch('/keepers/'.$this->profile->id, $this->profileData(['extra_city_ids' => [$this->prizren->id]]))->assertRedirect('/dashboard');
        $this->assertSame([1 => $this->prizren->id, 6 => $this->prishtine->id], $this->profile->weeklyHours()->pluck('city_id', 'weekday')->all());

        // Omitting the field keeps the extra cities; an empty value (what the form sends for "none") clears them.
        $this->patch('/keepers/'.$this->profile->id, $this->profileData())->assertRedirect('/dashboard');
        $this->assertSame([$this->prizren->id], $this->profile->additionalCities()->pluck('cities.id')->all());
        $this->patch('/keepers/'.$this->profile->id, $this->profileData(['extra_city_ids' => '']))->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->assertSame([], $this->profile->additionalCities()->pluck('cities.id')->all());
        $this->assertSame([1 => $this->prishtine->id, 6 => $this->prishtine->id], $this->profile->weeklyHours()->pluck('city_id', 'weekday')->all());
    }

    public function test_dashboard_rejects_extra_cities_from_another_country(): void
    {
        $tirane = City::where('name', 'Tiranë')->firstOrFail();
        $this->actingAs($this->owner)->patch('/keepers/'.$this->profile->id, $this->profileData(['extra_city_ids' => [$tirane->id]]))
            ->assertSessionHasErrors('extra_city_ids.0');
        $this->assertSame(0, $this->profile->additionalCities()->count());
    }

    public function test_each_weekly_day_and_extra_date_must_use_one_of_the_profile_cities(): void
    {
        $this->profile->syncAdditionalCities([$this->prizren->id]);

        $this->saveWeeklyHours(['days' => [['weekday' => 2, 'start' => '18:00', 'end' => '23:00', 'city_id' => $this->gjilan->id]]])
            ->assertSessionHasErrors('days.0.city_id');
        $this->assertSame(0, $this->profile->weeklyHours()->count());

        $extraDate = $this->nextWeekday(4);
        $this->saveWeeklyHours(['days' => [], 'offDates' => [], 'extraDates' => [$extraDate], 'extraDateCities' => [$extraDate => $this->gjilan->id]])
            ->assertSessionHasErrors('extraDateCities.'.$extraDate);
        $this->saveWeeklyHours(['days' => [], 'offDates' => [], 'extraDates' => [], 'extraDateCities' => [$extraDate => $this->prizren->id]])
            ->assertSessionHasErrors('extraDateCities');

        $this->saveWeeklyHours([
            'days' => [
                ['weekday' => 1, 'start' => '18:00', 'end' => '23:00', 'city_id' => $this->prizren->id],
                ['weekday' => 3, 'start' => '18:00', 'end' => '23:00', 'city_id' => $this->prizren->id],
                ['weekday' => 6, 'start' => '12:00', 'end' => '16:00'],
            ],
            'offDates' => [], 'extraDates' => [$extraDate], 'extraDateCities' => [$extraDate => $this->prizren->id],
        ])->assertRedirect('/dashboard')->assertSessionHasNoErrors();

        $this->assertSame([1 => $this->prizren->id, 3 => $this->prizren->id, 6 => $this->prishtine->id], $this->profile->weeklyHours()->pluck('city_id', 'weekday')->all());
        $this->assertSame($this->prizren->id, $this->profile->availabilities()->sole()->city_id);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('extraDateCities', [$extraDate => $this->prizren->id]));
    }

    public function test_browse_city_filter_matches_secondary_cities(): void
    {
        $this->assertArrayNotHasKey($this->profile->id, $this->browseKeepers('city_id='.$this->prizren->id));
        $this->profile->syncAdditionalCities([$this->prizren->id]);

        $keepers = $this->browseKeepers('city_id='.$this->prizren->id);
        $this->assertArrayHasKey($this->profile->id, $keepers);
        $this->assertSame([['id' => $this->prishtine->id, 'name' => 'Prishtinë'], ['id' => $this->prizren->id, 'name' => 'Prizren']], $keepers[$this->profile->id]['cities']);
        $this->assertArrayHasKey($this->profile->id, $this->browseKeepers('city_id='.$this->prishtine->id));
        $this->assertArrayNotHasKey($this->profile->id, $this->browseKeepers('city_id='.$this->gjilan->id));
        $this->assertArrayHasKey($this->profile->id, $this->browseKeepers('country_id='.$this->prishtine->country_id));

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/goalkeepers?city_id='.$this->prizren->id)->assertInertia(fn (Assert $page) => $page
            ->where('profiles.data', fn ($profiles) => collect($profiles)->contains(fn ($row) => $row['id'] === $this->profile->id && $row['city'] === 'Prishtinë, Prizren')));
    }

    public function test_browse_date_and_city_use_the_city_for_that_weekday(): void
    {
        $this->profile->syncAdditionalCities([$this->prizren->id]);
        $monday = $this->nextWeekday(1);
        $saturday = $this->nextWeekday(6);
        $this->saveWeeklyHours(['days' => [
            ['weekday' => 1, 'start' => '18:00', 'end' => '23:00', 'city_id' => $this->prizren->id],
            ['weekday' => 6, 'start' => '12:00', 'end' => '16:00', 'city_id' => $this->prishtine->id],
        ]])->assertRedirect('/dashboard');

        $inPrizren = $this->browseKeepers('city_id='.$this->prizren->id.'&date='.$monday)[$this->profile->id];
        $this->assertTrue($inPrizren['availableOnRequestedDate']);
        $this->assertSame('Prizren', $inPrizren['availableCityName']);
        $this->assertFalse($this->browseKeepers('city_id='.$this->prishtine->id.'&date='.$monday)[$this->profile->id]['availableOnRequestedDate']);
        $this->assertFalse($this->browseKeepers('city_id='.$this->prizren->id.'&date='.$saturday)[$this->profile->id]['availableOnRequestedDate']);
        $this->assertTrue($this->browseKeepers('city_id='.$this->prishtine->id.'&date='.$saturday)[$this->profile->id]['availableOnRequestedDate']);

        // Without a city filter the keeper is available on both days, each in its own city.
        $this->assertSame('Prizren', $this->browseKeepers('date='.$monday)[$this->profile->id]['availableCityName']);
        $this->assertSame('Prishtinë', $this->browseKeepers('date='.$saturday)[$this->profile->id]['availableCityName']);
        // In the city filter the card lists only the dates in that city.
        $prizrenDates = $inPrizren['availabilityDates'];
        $this->assertNotEmpty($prizrenDates);
        $this->assertTrue(collect($prizrenDates)->every(fn ($date) => CarbonImmutable::parse($date)->dayOfWeekIso === 1));
    }

    public function test_public_profile_shows_all_cities_and_the_city_of_each_day(): void
    {
        $this->profile->syncAdditionalCities([$this->prizren->id]);
        $monday = $this->nextWeekday(1);
        $this->saveWeeklyHours(['days' => [['weekday' => 1, 'start' => '18:00', 'end' => '23:00', 'city_id' => $this->prizren->id]]])->assertRedirect('/dashboard');

        $this->get('/keepers/'.$this->profile->id.'?date='.$monday)->assertInertia(fn (Assert $page) => $page
            ->where('keeper.cities.1.name', 'Prizren')
            ->where('keeper.weeklyHours.0.cityId', $this->prizren->id)->where('keeper.weeklyHours.0.cityName', 'Prizren')
            ->where('calendar.0.iso', $monday)->where('calendar.0.cityName', 'Prizren')->where('calendar.1.cityName', null)
            ->where('shareDescription', 'Plays in Prishtinë and Prizren.'));
    }

    public function test_rows_without_a_city_count_as_the_primary_city(): void
    {
        $this->profile->syncAdditionalCities([$this->prizren->id]);
        $monday = $this->nextWeekday(1);
        DB::table('goalkeeper_weekly_hours')->insert([
            'goalkeeper_profile_id' => $this->profile->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '23:00',
            'city_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([$monday], array_slice(AvailabilityCalendar::upcomingDates($this->profile->fresh(), $this->prishtine->id), 0, 1));
        $this->assertSame([], AvailabilityCalendar::upcomingDates($this->profile->fresh(), $this->prizren->id));
        $this->get('/keepers/'.$this->profile->id)->assertInertia(fn (Assert $page) => $page->where('keeper.weeklyHours.0.cityName', 'Prishtinë'));
    }

    public function test_goalkeeper_export_lists_other_cities(): void
    {
        $this->profile->syncAdditionalCities([$this->prizren->id, $this->gjilan->id]);
        $owner = User::factory()->create(['email' => User::SUPER_ADMIN_EMAIL, 'is_admin' => true]);
        $content = $this->actingAs($owner)->get('/admin/export/goalkeepers.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('city,other_cities,country', $content);
        $this->assertStringContainsString('Prishtinë,"Gjilan Prizren",Kosovë', $content);
    }
}
