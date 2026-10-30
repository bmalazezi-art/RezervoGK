<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\GoalkeeperContact;
use App\Models\GoalkeeperProfile;
use App\Models\MatchFormat;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Database\Seeders\MarketplaceCitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoalkeeperDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        foreach (GoalkeeperProfile::all() as $profile) {
            $profile->user()->associate(User::factory()->create());
            $profile->save();
        }
    }

    public function test_demo_seeding_is_repeatable_and_relationships_are_preserved(): void
    {
        $this->seed(GoalkeeperDemoSeeder::class);
        $this->assertDatabaseCount('countries', 3);
        $curatedCityCount = collect(MarketplaceCitySeeder::locations())->sum(fn (array $cities) => count($cities));
        $this->assertDatabaseCount('cities', $curatedCityCount);
        $this->assertDatabaseCount('match_formats', 3);
        $this->assertDatabaseCount('goalkeeper_profiles', 4);
        $this->assertDatabaseCount('goalkeeper_contacts', 4);
        $this->assertDatabaseCount('goalkeeper_profile_match_format', 8);
        $this->assertDatabaseCount('users', 4); // Owners belong to the public test fixtures; seeding adds no accounts.
        $profile = GoalkeeperProfile::where('slug', 'leon-dervishi')->firstOrFail();
        $this->assertSame(700, $profile->price_cents);
        $this->assertSame('AL', $profile->city->country->code);
        $this->assertSame(['5v5', '7v7'], $profile->matchFormats->pluck('code')->all());
        $this->assertSame('+35568111222', $profile->contact->phone);
        $this->assertSame($profile->id, $profile->contact->goalkeeperProfile->id);
        $this->assertTrue($profile->city->goalkeeperProfiles->contains($profile));
    }

    public function test_browse_and_profile_read_current_database_values(): void
    {
        $profile = GoalkeeperProfile::where('slug', 'leon-dervishi')->firstOrFail();
        $profile->update(['name' => 'Updated Keeper', 'price_cents' => 725, 'bio' => 'New biography', 'languages' => ['English'], 'experience_years' => 8, 'matches_played' => 70, 'is_verified' => false]);
        $profile->contact->update(['phone' => '+355691234567', 'whatsapp' => '355699876543']);
        $profile->matchFormats()->sync([MatchFormat::where('code', '6v6')->value('id')]);

        $this->get('/browse')->assertInertia(fn (Assert $page) => $page
            ->component('Browse')->has('keepers', 4)
            ->where('keepers.1.name', 'Updated Keeper')->where('keepers.1.price', 7.25)
            ->where('keepers.1.formats', ['6v6']));
        $this->get("/keepers/{$profile->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Profile')->where('keeper.bio', 'New biography')
            ->where('keeper.price', 7.25)->where('keeper.phone', '355691234567')
            ->where('keeper.whatsapp', '355699876543')->where('keeper.languages', ['English'])
            ->where('keeper.experienceYears', 8)->missing('keeper.matchesPlayed')
            ->missing('keeper.verified'));
    }

    public function test_city_price_and_format_filters_combine_and_preserve_url_state(): void
    {
        $city = City::where('name', 'Prizren')->firstOrFail();
        $this->get("/browse?city_id={$city->id}&max_price=5&format=6v6")
            ->assertInertia(fn (Assert $page) => $page->has('keepers', 1)
                ->where('keepers.0.name', 'Altin Berisha')
                ->where('filters.city_id', (string) $city->id)
                ->where('filters.max_price', 5)->where('filters.format', '6v6'));
        $this->get("/browse?city_id={$city->id}&format=7v7")
            ->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->has('keepers', 4));
    }

    public function test_each_filter_works_independently(): void
    {
        $city = City::where('name', 'Tiranë')->firstOrFail();
        $country = Country::where('code', 'XK')->firstOrFail();
        $this->get("/browse?city_id={$city->id}&currency=EUR")->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 1)->where('keepers.0.name', 'Leon Dervishi'));
        $this->get('/browse?max_price=7')->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 2)->where('keepers.0.name', 'Leon Dervishi')->where('keepers.1.name', 'Altin Berisha'));
        $this->get('/browse?format=7v7')->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 2)->where('keepers.0.name', 'Leon Dervishi')->where('keepers.1.name', 'Enis Hasani'));
        $this->get("/browse?country_id={$country->id}")->assertInertia(fn (Assert $page) => $page->has('keepers', 2));
    }

    public function test_country_search_without_city_shows_all_goalkeepers_and_indicates_requested_date_availability(): void
    {
        $date = now()->addDays(3)->toDateString();
        $kosovo = Country::where('code', 'XK')->firstOrFail();
        $prishtinaKeeper = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $prizrenKeeper = GoalkeeperProfile::where('slug', 'altin-berisha')->firstOrFail();
        $albaniaKeeper = GoalkeeperProfile::where('slug', 'leon-dervishi')->firstOrFail();

        $prishtinaKeeper->availabilities()->create(['available_on' => $date]);
        $albaniaKeeper->availabilities()->create(['available_on' => $date]);

        $this->get("/browse?country_id={$kosovo->id}&date={$date}")
            ->assertInertia(fn (Assert $page) => $page->has('keepers', 2)
                ->where('keepers.0.id', $prishtinaKeeper->id)
                ->where('keepers.0.availableOnRequestedDate', true)
                ->where('keepers.1.id', $prizrenKeeper->id)
                ->where('keepers.1.availableOnRequestedDate', false)
                ->where('filters.country_id', (string) $kosovo->id)
                ->where('filters.city_id', '')
                ->where('filters.date', $date));
    }

    public function test_price_filter_uses_an_inclusive_cent_boundary(): void
    {
        $this->get('/browse?max_price=6.99')->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 1)->where('keepers.0.price', 5));
        $this->get('/browse?max_price=7.00')->assertInertia(fn (Assert $page) => $page->has('keepers', 2));
        $this->get('/browse?max_price=0')->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
    }

    public function test_database_profiles_are_not_limited_to_the_four_demo_ids(): void
    {
        $copy = GoalkeeperProfile::firstOrFail()->replicate();
        $copy->user_id = User::factory()->create()->id;
        $copy->slug = 'fifth-keeper';
        $copy->name = 'Fifth Keeper';
        $copy->save();
        $this->get("/keepers/{$copy->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('keeper.name', 'Fifth Keeper')->where('keeper.phone', '')->where('keeper.whatsapp', null));
    }

    public function test_deleting_a_profile_cascades_contacts_and_format_links(): void
    {
        $profile = GoalkeeperProfile::firstOrFail();
        $id = $profile->id;
        $profile->delete();
        $this->assertDatabaseMissing('goalkeeper_contacts', ['goalkeeper_profile_id' => $id]);
        $this->assertDatabaseMissing('goalkeeper_profile_match_format', ['goalkeeper_profile_id' => $id]);
        $this->assertSame(3, GoalkeeperContact::count());
        $this->assertSame(3, MatchFormat::count());
    }

    public function test_an_empty_database_returns_an_empty_listing(): void
    {
        GoalkeeperProfile::query()->delete();
        $this->get('/browse')->assertOk()->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
    }

    public static function invalidFilters(): array
    {
        return [['city_id=999'], ['city_id[]=1'], ['country_id=999'], ['format=11v11'], ['max_price=-1'], ['max_price=abc'], ['max_price=7.001'], ['max_price=1000001']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $query): void
    {
        $this->getJson('/browse?'.$query)->assertUnprocessable();
    }

    public function test_isolated_tests_use_only_in_memory_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }
}
