<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GoalkeeperProfileReportTest extends TestCase
{
    use RefreshDatabase;

    private GoalkeeperProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        $this->profile = GoalkeeperProfile::firstOrFail();
        $this->profile->user()->associate(User::factory()->create())->save();
    }

    public function test_anonymous_user_can_report_only_a_public_claimed_profile(): void
    {
        $this->post('/keepers/'.$this->profile->id.'/reports', ['reason' => 'inaccurate', 'details' => 'Wrong city'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('goalkeeper_profile_reports', ['goalkeeper_profile_id' => $this->profile->id, 'reason' => 'inaccurate', 'details' => 'Wrong city', 'status' => 'open']);
        $unclaimed = GoalkeeperProfile::whereNull('user_id')->firstOrFail();
        $this->postJson('/keepers/'.$unclaimed->id.'/reports', ['reason' => 'spam'])->assertNotFound();
        $this->assertDatabaseCount('goalkeeper_profile_reports', 1);
        DB::table('goalkeeper_profiles')->where('id', $this->profile->id)->update(['is_published' => false]);
        $this->postJson('/keepers/'.$this->profile->id.'/reports', ['reason' => 'spam'])->assertNotFound();
        $this->assertDatabaseCount('goalkeeper_profile_reports', 1);
    }

    public function test_admin_can_set_each_report_status_and_invalid_status_is_rejected_without_suspending_profile(): void
    {
        $this->postJson('/keepers/'.$this->profile->id.'/reports', ['reason' => 'made-up'])->assertUnprocessable();
        $this->post('/keepers/'.$this->profile->id.'/reports', ['reason' => 'other', 'details' => str_repeat('x', 1001)])->assertSessionHasErrors('details');
        $this->post('/keepers/'.$this->profile->id.'/reports', ['reason' => 'other'])->assertRedirect();
        $reportId = (int) DB::table('goalkeeper_profile_reports')->value('id');
        $this->actingAs(User::factory()->create())->get('/admin/reports')->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/reports')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminReports')->has('reports.data', 1));
        foreach (['open', 'reviewed', 'dismissed'] as $status) {
            $this->patch('/admin/reports/'.$reportId, ['status' => $status, 'reason' => 'Administrative status review'])->assertRedirect();
            $this->assertDatabaseHas('goalkeeper_profile_reports', ['id' => $reportId, 'status' => $status]);
        }
        $this->patchJson('/admin/reports/'.$reportId, ['status' => 'suspended'])->assertUnprocessable();
        $this->assertFalse($this->profile->fresh()->is_suspended);
    }
}
