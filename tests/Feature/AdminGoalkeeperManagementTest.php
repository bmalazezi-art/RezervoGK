<?php

namespace Tests\Feature;

use App\Models\AdminListingAudit;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminGoalkeeperManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite()->seed(GoalkeeperDemoSeeder::class);
    }

    public function test_admin_directory_supports_search_location_visibility_reports_and_sorting(): void
    {
        $profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $profile->user()->associate(User::factory()->create())->save();
        $profile->reports()->create(['reason' => 'inaccurate', 'status' => 'open']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/goalkeepers?search=Ardit&country_id='.$profile->city->country_id.'&city_id='.$profile->city_id.'&visibility=public&reported=reported&sort=name&direction=asc')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminGoalkeepers')
                ->where('profiles.total', 1)->where('profiles.data.0.name', 'Ardit Krasniqi')
                ->where('profiles.data.0.openReports', 1)->where('filters.sort', 'name'));
        $this->actingAs(User::factory()->create())->get('/admin/goalkeepers')->assertForbidden();
    }

    public function test_moderation_hides_and_restores_without_changing_publication_or_deleting_reports(): void
    {
        $profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $owner = User::factory()->create();
        $profile->user()->associate($owner)->save();
        $profile->reports()->create(['reason' => 'inaccurate', 'details' => 'Keep report history', 'status' => 'open']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertTrue(GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists());

        $this->actingAs($admin)->patch('/admin/goalkeepers/'.$profile->id.'/moderation', ['hidden' => true, 'reason' => 'Listing requires a policy review'])
            ->assertRedirect();
        $profile->refresh();
        $this->assertTrue($profile->admin_hidden);
        $this->assertTrue($profile->is_published);
        $this->assertTrue(GoalkeeperProfile::whereKey($profile->id)->exists());
        $this->assertFalse(GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists());
        $this->get('/keepers/'.$profile->id)->assertNotFound();
        $this->assertDatabaseHas('goalkeeper_profile_reports', ['goalkeeper_profile_id' => $profile->id, 'details' => 'Keep report history']);
        $this->get('/admin/reports')->assertInertia(fn (Assert $page) => $page->where('reports.data.0.profileName', 'Ardit Krasniqi'));

        $this->patch('/admin/goalkeepers/'.$profile->id.'/moderation', ['hidden' => false, 'reason' => 'Policy review completed successfully'])
            ->assertRedirect();
        $profile->refresh();
        $this->assertFalse($profile->admin_hidden);
        $this->assertTrue($profile->is_published);
        $this->assertTrue(GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists());
        $this->assertSame(2, AdminListingAudit::where('goalkeeper_profile_id', $profile->id)->count());
    }

    public function test_restoration_respects_owner_preference_existing_moderation_and_suspended_accounts(): void
    {
        $profile = GoalkeeperProfile::where('slug', 'leon-dervishi')->firstOrFail();
        $owner = User::factory()->create();
        $profile->user()->associate($owner)->save();
        $profile->is_published = false;
        $profile->is_suspended = true;
        $profile->admin_hidden = true;
        $profile->save();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch('/admin/goalkeepers/'.$profile->id.'/moderation', ['hidden' => false, 'reason' => 'Separate review restriction resolved'])
            ->assertRedirect();
        $profile->refresh();
        $this->assertFalse($profile->admin_hidden);
        $this->assertFalse($profile->is_published);
        $this->assertTrue($profile->is_suspended);
        $this->assertFalse(GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists());

        $profile->forceFill(['is_published' => true, 'is_suspended' => false])->save();
        $owner->forceFill(['is_suspended' => true])->save();
        $this->assertFalse(GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists());
    }

    public function test_public_browse_hides_and_restores_listing_without_publishing_an_unpublished_profile(): void
    {
        $profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $profile->user()->associate(User::factory()->create())->save();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->where('keepers.0.id', $profile->id));
        $this->actingAs($admin)->patch('/admin/goalkeepers/'.$profile->id.'/moderation', [
            'hidden' => true, 'reason' => 'Check the public profile before visibility resumes',
        ])->assertRedirect();
        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->missing('keepers.0.id'));

        $this->patch('/admin/goalkeepers/'.$profile->id.'/moderation', [
            'hidden' => false, 'reason' => 'The review is complete and no issue remains',
        ])->assertRedirect();
        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->where('keepers.0.id', $profile->id));

        $profile->forceFill(['is_published' => false, 'admin_hidden' => true])->save();
        $this->patch('/admin/goalkeepers/'.$profile->id.'/moderation', [
            'hidden' => false, 'reason' => 'Moderation restriction lifted after review',
        ])->assertRedirect();
        $this->assertFalse($profile->fresh()->is_published);
        $this->get('/browse')->assertInertia(fn (Assert $page) => $page->missing('keepers.0.id'));
    }

    public function test_moderation_requires_admin_and_a_reason_and_details_keep_profile_data_private(): void
    {
        $profile = GoalkeeperProfile::where('slug', 'enis-hasani')->firstOrFail();
        $this->actingAs(User::factory()->create())->patch('/admin/goalkeepers/'.$profile->id.'/moderation', ['hidden' => true, 'reason' => 'Forbidden action'])
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->patch('/admin/goalkeepers/'.$profile->id.'/moderation', ['hidden' => true, 'reason' => 'short'])
            ->assertSessionHasErrors('reason');
        $this->actingAs(User::factory()->create())->get('/admin/goalkeepers/'.$profile->id)->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        AdminListingAudit::create([
            'goalkeeper_profile_id' => $profile->id, 'actor_id' => $admin->id,
            'action' => 'hidden', 'reason' => 'Reviewed a user report.', 'created_at' => now(),
        ]);
        $this->actingAs($admin)->get('/admin/goalkeepers/'.$profile->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminGoalkeeperDetails')
                ->where('profile.name', 'Enis Hasani')->where('moderationHistory.0.reason', 'Reviewed a user report.')
                ->missing('profile.contact')->missing('profile.user.password'));
    }
}
