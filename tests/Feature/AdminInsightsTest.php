<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\CreateDemoUsersSeeder;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite()->seed([GoalkeeperDemoSeeder::class, CreateDemoUsersSeeder::class]);
    }

    public function test_searches_are_counted_once_per_session_without_personal_data(): void
    {
        $prishtina = City::where('name', 'Prishtinë')->firstOrFail();
        $empty = City::whereDoesntHave('goalkeeperProfiles')->firstOrFail();

        $this->get('/browse?city_id='.$prishtina->id)->assertOk();
        $this->get('/browse?city_id='.$prishtina->id)->assertOk();           // same session, same day: once
        $this->get('/browse?city_id='.$prishtina->id.'&page=2')->assertOk();  // paging is not a new search
        $this->get('/browse?city_id='.$empty->id)->assertOk();

        $this->assertSame(1, (int) DB::table('search_demand')->where('city_id', $prishtina->id)->value('searches'));
        $this->assertSame(0, (int) DB::table('search_demand')->where('city_id', $prishtina->id)->value('zero_results'));
        $this->assertSame(1, (int) DB::table('search_demand')->where('city_id', $empty->id)->value('zero_results'));
        $this->assertSame($prishtina->country_id, (int) DB::table('search_demand')->where('city_id', $prishtina->id)->value('country_id'));
        $this->assertSame(['id', 'event_date', 'country_id', 'city_id', 'format', 'searches', 'zero_results', 'created_at', 'updated_at'],
            array_keys((array) DB::table('search_demand')->first()));
    }

    public function test_bots_and_admins_are_not_counted(): void
    {
        $this->withHeader('User-Agent', 'WhatsApp/2.23 link preview')->get('/browse')->assertOk();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/browse')->assertOk();

        $this->assertDatabaseCount('search_demand', 0);
    }

    public function test_insights_page_is_admin_only_and_shows_gaps_and_contacts(): void
    {
        $empty = City::whereDoesntHave('goalkeeperProfiles')->firstOrFail();
        $this->get('/browse?city_id='.$empty->id);
        $profile = GoalkeeperProfile::publiclyVisible()->firstOrFail();
        DB::table('goalkeeper_profile_analytics')->insert([
            ['goalkeeper_profile_id' => $profile->id, 'event_date' => now()->toDateString(), 'event_type' => 'view', 'event_count' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['goalkeeper_profile_id' => $profile->id, 'event_date' => now()->toDateString(), 'event_type' => 'whatsapp', 'event_count' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        auth()->logout();

        $this->get('/admin/insights')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/insights')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/insights')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AdminInsights')
                ->where('demand.searches', 1)->where('demand.zeroResults', 1)
                ->where('demand.places.0.city', $empty->name)->where('demand.places.0.goalkeepersNow', 0)
                ->where('contacts.views', 10)->where('contacts.whatsapp', 2)->missing('contacts.contactRate')
                ->where('contacts.top.0.id', $profile->id)->where('contacts.top.0.url', '/p/'.$profile->slug));
    }

    public function test_admin_can_feature_and_verify_a_goalkeeper_with_an_audit_trail(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = GoalkeeperProfile::publiclyVisible()->orderByDesc('id')->firstOrFail();
        GoalkeeperProfile::query()->update(['is_featured' => false]);

        $this->actingAs($admin)->patch("/admin/goalkeepers/{$profile->id}/highlight", ['field' => 'verified', 'value' => true, 'reason' => 'Checked phone and identity.'])->assertRedirect();
        $this->actingAs($admin)->patch("/admin/goalkeepers/{$profile->id}/highlight", ['field' => 'featured', 'value' => true, 'reason' => 'Active and well reviewed.'])->assertRedirect();
        $this->actingAs($admin)->patch("/admin/goalkeepers/{$profile->id}/highlight", ['field' => 'featured', 'value' => true, 'reason' => 'short'])->assertSessionHasErrors('reason');

        $this->assertNotNull($profile->fresh()->verified_at);
        $this->assertDatabaseHas('admin_listing_audits', ['goalkeeper_profile_id' => $profile->id, 'action' => 'verified', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('admin_listing_audits', ['goalkeeper_profile_id' => $profile->id, 'action' => 'featured']);
        auth()->logout();

        $this->get('/browse?max_price=1000')->assertInertia(fn (Assert $page) => $page
            ->where('keepers.0.id', $profile->id)->where('keepers.0.verifiedByAdmin', true)->missing('keepers.0.verified'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('keepers.0.id', $profile->id));

        $this->actingAs(User::factory()->create())->patch("/admin/goalkeepers/{$profile->id}/highlight", ['field' => 'verified', 'value' => false, 'reason' => 'Not allowed for users.'])->assertForbidden();
    }
}
