<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\AdminUserAudit;
use App\Models\GoalkeeperProfileReport;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_is_protected_and_shows_existing_marketplace_data(): void
    {
        $this->withoutVite()->seed(GoalkeeperDemoSeeder::class);
        $profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $user = User::factory()->create(['name' => 'Recent User', 'email' => 'recent@example.test', 'created_at' => now()->addMinute()]);
        $profile->user()->associate($user);
        $profile->save();
        $profile->reports()->create(['reason' => 'inaccurate', 'status' => 'open']);

        $this->get('/admin')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('AdminDashboard')
            ->where('stats.totalUsers', 3)
            ->where('stats.totalProfiles', 4)
            ->where('stats.activeProfiles', 1)
            ->where('stats.newRegistrations7Days', 3)
            ->where('stats.newRegistrations30Days', 3)
            ->where('stats.pendingReports', 1)
            ->has('growth', 30)
            ->where('growth.29.users', 3)
            ->has('profileVisibility.published')
            ->has('usersByCountry', 2)
            ->where('usersByCountry.1.country', 'Kosovë')
            ->where('usersByCountry.1.total', 1)
            ->where('recentRegistrations.0.email', 'recent@example.test')
            ->where('recentReports.0.profileName', 'Ardit Krasniqi'));
    }

    public function test_only_open_reports_are_included_in_the_moderation_queue(): void
    {
        $this->withoutVite()->seed(GoalkeeperDemoSeeder::class);
        $profile = GoalkeeperProfile::firstOrFail();
        $profile->reports()->create(['reason' => 'spam', 'status' => 'reviewed']);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('recentReports', [])->where('stats.pendingReports', 0));
    }

    public function test_user_directory_is_admin_only_and_reuses_existing_accounts(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
        $listed = User::factory()->create(['name' => 'Directory User', 'email' => 'directory@example.test', 'created_at' => now()->addMinute()]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/users')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminUsers')
                ->where('users.data.0.id', $listed->id)
                ->where('users.data.0.email', 'directory@example.test'));
    }

    public function test_user_directory_search_filters_and_sorts_using_existing_profile_locations(): void
    {
        $this->withoutVite()->seed(GoalkeeperDemoSeeder::class);
        $profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $keeper = User::factory()->create(['name' => 'Filtered Keeper', 'email' => 'filter@example.test']);
        $profile->user()->associate($keeper)->save();
        User::factory()->create(['name' => 'Plain Account', 'email' => 'plain@example.test']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/users?search=filter&account_type=goalkeeper&country_id='.$profile->city->country_id.'&city_id='.$profile->city_id.'&sort=name&direction=asc')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('users.total', 1)
                ->where('users.data.0.email', 'filter@example.test')
                ->where('users.data.0.city', 'Prishtinë')
                ->where('filters.direction', 'asc'));
    }

    public function test_user_details_and_suspension_are_authorized_audited_and_protect_admin_accounts(): void
    {
        $this->withoutVite()->seed(GoalkeeperDemoSeeder::class);
        $profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $target = User::factory()->create(['name' => 'Keeper Owner']);
        $profile->user()->associate($target)->save();
        $profile->forceFill(['is_published' => false])->save();
        $profile->reports()->create(['reason' => 'inaccurate', 'details' => 'Check listing', 'status' => 'open']);
        $admin = User::factory()->create(['is_admin' => true]);
        $otherAdmin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/users/'.$target->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('AdminUserDetails')->where('user.email', $target->email)
            ->where('profile.city', 'Prishtinë')->where('reports.0.details', 'Check listing'));
        $this->patch('/admin/users/'.$target->id.'/suspension', ['suspended' => true, 'reason' => 'Repeated abusive behavior'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertTrue($target->fresh()->is_suspended);
        $this->actingAs($target->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertDatabaseHas('admin_user_audits', ['actor_id' => $admin->id, 'target_id' => $target->id, 'action' => 'suspended']);
        $this->actingAs($admin);
        $this->patch('/admin/users/'.$target->id.'/suspension', ['suspended' => false, 'reason' => 'Appeal reviewed and accepted'])
            ->assertRedirect();
        $this->assertFalse($target->fresh()->is_suspended);
        $this->assertFalse($profile->fresh()->is_published);
        $this->assertFalse(GoalkeeperProfile::publiclyVisible()->whereKey($profile->id)->exists());
        $this->assertSame(2, AdminUserAudit::where('target_id', $target->id)->count());
        $this->patch('/admin/users/'.$otherAdmin->id.'/suspension', ['suspended' => true, 'reason' => 'Test protected administrator'])
            ->assertForbidden();
        $this->patch('/admin/users/'.$admin->id.'/suspension', ['suspended' => true, 'reason' => 'Test self protection'])
            ->assertForbidden();
        $this->assertFalse($admin->fresh()->is_suspended);
        $this->assertFalse($otherAdmin->fresh()->is_suspended);
    }

    public function test_suspended_accounts_cannot_log_in_and_admin_routes_cannot_be_used_by_non_admins(): void
    {
        $suspended = User::factory()->create(['email' => 'suspended@example.test', 'password' => 'Secure-Password-123!', 'is_suspended' => true]);
        $this->post('/login', ['email' => $suspended->email, 'password' => 'Secure-Password-123!'])->assertSessionHasErrors('email');
        $this->actingAs(User::factory()->create())->patch('/admin/users/'.$suspended->id.'/suspension', ['suspended' => false, 'reason' => 'Should not be allowed'])->assertForbidden();
    }
}
