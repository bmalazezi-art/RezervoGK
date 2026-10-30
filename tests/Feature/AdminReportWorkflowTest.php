<?php

namespace Tests\Feature;

use App\Models\AdminListingAudit;
use App\Models\AdminReportAudit;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private GoalkeeperProfile $profile;
    private User $owner;
    private int $reportId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite()->seed(GoalkeeperDemoSeeder::class);
        $this->profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $this->owner = User::factory()->create(['name' => 'Profile Owner', 'email' => 'owner@example.test']);
        $this->profile->user()->associate($this->owner)->save();
        $this->profile->reports()->create(['reason' => 'spam', 'details' => 'Review this public listing']);
        $this->reportId = (int) $this->profile->reports()->value('id');
    }

    public function test_admin_report_search_filters_and_sorting_show_existing_reports(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/reports?search=Ardit&status=open&reason=spam&date_from='.now()->toDateString().'&date_to='.now()->toDateString().'&sort=status&direction=asc')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminReports')
                ->where('reports.total', 1)->where('reports.data.0.profileName', 'Ardit Krasniqi')
                ->where('reports.data.0.status', 'open')->where('filters.sort', 'status'));
        $this->actingAs(User::factory()->create())->get('/admin/reports')->assertForbidden();
    }

    public function test_moderation_center_reuses_report_workflow_and_keeps_admin_authorization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/moderation?status=open')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminReports')
                ->where('basePath', '/admin/moderation')->where('reports.total', 1));

        $this->get('/admin/moderation/'.$this->reportId)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AdminReportDetails')
                ->where('basePath', '/admin/moderation')->where('report.id', $this->reportId));

        $this->patch('/admin/moderation/'.$this->reportId, [
            'status' => 'dismissed', 'reason' => 'Reviewed and no action is required.',
        ])->assertRedirect();
        $this->assertDatabaseHas('goalkeeper_profile_reports', ['id' => $this->reportId, 'status' => 'dismissed']);
        $this->assertDatabaseHas('admin_report_audits', ['goalkeeper_profile_report_id' => $this->reportId, 'event' => 'status_changed']);

        $this->actingAs(User::factory()->create())->get('/admin/moderation')->assertForbidden();
    }

    public function test_detail_status_and_internal_notes_are_audited_and_private(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/reports/'.$this->reportId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('AdminReportDetails')->where('report.reason', 'spam')->where('report.reporter', 'Anonim (raporti nuk ruan identitetin e raportuesit)')
            ->where('profile.user.email', $this->owner->email)->has('activity', 0));

        $this->patch('/admin/reports/'.$this->reportId, ['status' => 'reviewed'])->assertSessionHasErrors('reason');
        $this->patch('/admin/reports/'.$this->reportId, ['status' => 'reviewed', 'reason' => 'Reviewed and confirmed as appropriate'])
            ->assertRedirect();
        $this->patch('/admin/reports/'.$this->reportId, ['note' => 'Keep a record of this internal decision.'])->assertRedirect();
        $this->assertDatabaseHas('goalkeeper_profile_reports', ['id' => $this->reportId, 'status' => 'reviewed']);
        $this->assertDatabaseCount('admin_report_audits', 2);
        $this->actingAs($admin)->get('/admin/reports/'.$this->reportId)->assertInertia(fn (Assert $page) => $page
            ->where('activity.0.event', 'note_added')->where('activity.1.fromStatus', 'open')->where('activity.1.toStatus', 'reviewed'));
        $this->expectException(\LogicException::class);
        AdminReportAudit::firstOrFail()->update(['note' => 'tampered']);
    }

    public function test_report_workflow_hides_and_restores_listing_with_existing_listing_audit_and_keeps_report(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/reports/'.$this->reportId.'/listing-moderation', [
            'hidden' => true, 'reason' => 'Listing requires review after report',
        ])->assertRedirect();
        $this->assertTrue($this->profile->fresh()->admin_hidden);
        $this->assertDatabaseHas('goalkeeper_profile_reports', ['id' => $this->reportId, 'status' => 'open']);
        $this->assertDatabaseCount('admin_listing_audits', 1);
        $this->get('/admin/reports/'.$this->reportId)->assertOk();
        $this->post('/admin/reports/'.$this->reportId.'/listing-moderation', [
            'hidden' => false, 'reason' => 'Review completed and listing can return',
        ])->assertRedirect();
        $this->assertFalse($this->profile->fresh()->admin_hidden);
        $this->assertSame(2, AdminListingAudit::where('goalkeeper_profile_id', $this->profile->id)->count());
    }

    public function test_report_status_notes_and_listing_moderation_are_admin_only_and_validated(): void
    {
        $this->actingAs(User::factory()->create())->patch('/admin/reports/'.$this->reportId, ['note' => 'Unauthorized note'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->patch('/admin/reports/'.$this->reportId, ['note' => 'bad'])->assertSessionHasErrors('note');
        $this->post('/admin/reports/'.$this->reportId.'/listing-moderation', ['hidden' => true, 'reason' => 'Must be rejected'])->assertRedirect('/');
    }
}
