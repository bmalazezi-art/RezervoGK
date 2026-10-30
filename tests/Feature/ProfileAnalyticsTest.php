<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private GoalkeeperProfile $profile;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        $this->profile = GoalkeeperProfile::firstOrFail();
        $this->owner = User::factory()->create();
        $this->profile->user()->associate($this->owner)->save();
    }

    public function test_public_views_and_contact_clicks_are_aggregated_and_deduplicated_per_session_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 10:00:00');
        try {
            $this->get('/keepers/'.$this->profile->id)->assertOk();
            $this->get('/keepers/'.$this->profile->id)->assertOk();
            $this->postJson('/keepers/'.$this->profile->id.'/analytics', ['event' => 'whatsapp'])->assertNoContent();
            $this->postJson('/keepers/'.$this->profile->id.'/analytics', ['event' => 'whatsapp'])->assertNoContent();
            $this->postJson('/keepers/'.$this->profile->id.'/analytics', ['event' => 'phone'])->assertNoContent();

            $this->assertDatabaseHas('goalkeeper_profile_analytics', ['goalkeeper_profile_id' => $this->profile->id, 'event_date' => '2026-10-28', 'event_type' => 'view', 'event_count' => 1]);
            $this->assertDatabaseHas('goalkeeper_profile_analytics', ['goalkeeper_profile_id' => $this->profile->id, 'event_date' => '2026-10-28', 'event_type' => 'whatsapp', 'event_count' => 1]);
            $this->assertDatabaseHas('goalkeeper_profile_analytics', ['goalkeeper_profile_id' => $this->profile->id, 'event_date' => '2026-10-28', 'event_type' => 'phone', 'event_count' => 1]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_new_profiles_show_zero_state_and_dashboard_ranges_use_calendar_days(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 10:00:00');
        try {
            foreach ([
                ['2026-10-28', 'view', 2], ['2026-10-22', 'view', 3], ['2026-10-21', 'view', 5],
                ['2026-09-28', 'view', 7], ['2026-10-28', 'whatsapp', 4], ['2026-10-27', 'phone', 2],
            ] as [$date, $type, $count]) {
                DB::table('goalkeeper_profile_analytics')->insert(['goalkeeper_profile_id' => $this->profile->id, 'event_date' => $date, 'event_type' => $type, 'event_count' => $count, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('analytics', [
                'totalViews' => 17, 'views7Days' => 5, 'views30Days' => 10, 'whatsappClicks' => 4, 'phoneClicks' => 2,
            ]));

            DB::table('goalkeeper_profile_analytics')->where('goalkeeper_profile_id', $this->profile->id)->delete();
            $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('analytics', [
                'totalViews' => 0, 'views7Days' => 0, 'views30Days' => 0, 'whatsappClicks' => 0, 'phoneClicks' => 0,
            ]));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_owner_and_admin_traffic_is_excluded_and_private_profiles_cannot_record_events(): void
    {
        $this->actingAs($this->owner)->get('/keepers/'.$this->profile->id)->assertOk();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->postJson('/keepers/'.$this->profile->id.'/analytics', ['event' => 'phone'])->assertNoContent();
        $this->assertDatabaseCount('goalkeeper_profile_analytics', 0);

        DB::table('goalkeeper_profiles')->where('id', $this->profile->id)->update(['is_published' => false]);
        $this->postJson('/keepers/'.$this->profile->id.'/analytics', ['event' => 'whatsapp'])->assertNotFound();
        $this->get('/keepers/'.$this->profile->id)->assertNotFound();
        $this->assertDatabaseCount('goalkeeper_profile_analytics', 0);
    }

    public function test_invalid_contact_event_is_rejected(): void
    {
        $this->postJson('/keepers/'.$this->profile->id.'/analytics', ['event' => 'email'])->assertUnprocessable();
        $this->assertDatabaseCount('goalkeeper_profile_analytics', 0);
    }
}
