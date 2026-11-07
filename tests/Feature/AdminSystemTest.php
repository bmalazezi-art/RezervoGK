<?php

namespace Tests\Feature;

use App\Models\AdminSystemAudit;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_system_settings_are_admin_only_validated_audited_and_registration_setting_is_enforced(): void
    {
        $admin = User::factory()->create(['email' => 'bmalazezi@gmail.com', 'is_admin' => true]);
        $otherAdmin = User::factory()->create(['is_admin' => true]);
        $ordinary = User::factory()->create();

        $this->actingAs($ordinary)->get('/admin/settings')->assertForbidden();
        $this->actingAs($otherAdmin)->get('/admin/settings')->assertForbidden();
        $this->actingAs($otherAdmin)->patch('/admin/settings', [
            'registration_enabled' => false, 'announcement' => 'Unauthorized update.', 'reason' => 'An admin without Super Admin access.',
        ])->assertForbidden();
        $this->assertTrue(PlatformSetting::value('registration_enabled'));
        $this->actingAs($admin)->patch('/admin/settings', [
            'registration_enabled' => false, 'announcement' => 'Tryout registrations are temporarily paused.', 'reason' => 'We need a short pause for platform maintenance.',
        ])->assertRedirect();
        $this->assertSame(false, PlatformSetting::value('registration_enabled'));
        $this->assertSame('Tryout registrations are temporarily paused.', PlatformSetting::value('announcement'));
        $this->assertDatabaseHas('admin_system_audits', ['actor_id' => $admin->id, 'action' => 'settings_updated', 'reason' => 'We need a short pause for platform maintenance.']);
        auth()->logout();
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();

        $this->actingAs($admin)->patch('/admin/settings', [
            'registration_enabled' => 'anything', 'announcement' => str_repeat('x', 281), 'reason' => 'short',
        ])->assertSessionHasErrors(['registration_enabled', 'announcement', 'reason']);
        $this->assertSame(1, AdminSystemAudit::count());

        $this->actingAs($admin)->patch('/admin/settings', [
            'registration_enabled' => true, 'announcement' => '', 'reason' => 'Registration pause is complete and the site is ready.',
        ])->assertRedirect();
        $this->assertTrue(PlatformSetting::value('registration_enabled'));
        auth()->logout();
        $this->get('/register')->assertOk();
        $member = User::factory()->create(['email' => 'existing-member@example.test', 'password' => 'Existing-Member-123!']);
        $this->post('/login', ['email' => $member->email, 'password' => 'Existing-Member-123!'])->assertRedirect('/');
    }

    public function test_admin_access_changes_are_audited_and_cannot_self_demote_or_remove_last_admin(): void
    {
        $admin = User::factory()->create(['email' => 'bmalazezi@gmail.com', 'is_admin' => true]);
        $otherAdmin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();

        $this->actingAs($admin)->patch('/admin/admins/'.$admin->id, ['is_admin' => false, 'reason' => 'No self demotion allowed'])->assertForbidden();
        $this->actingAs($otherAdmin)->patch('/admin/admins/'.$target->id, ['is_admin' => true, 'reason' => 'Needs access to support moderation.'])->assertForbidden();
        $this->actingAs($admin)->patch('/admin/admins/'.$target->id, ['is_admin' => true, 'reason' => 'Needs access to support moderation.'])->assertRedirect();
        $this->assertTrue($target->fresh()->is_admin);
        $this->assertDatabaseHas('admin_system_audits', [
            'actor_id' => $admin->id, 'target_id' => $target->id, 'action' => 'admin_granted',
            'reason' => 'Needs access to support moderation.',
        ]);

        $this->patch('/admin/admins/'.$target->id, ['is_admin' => false, 'reason' => 'Their temporary admin work is completed.'])->assertRedirect();
        $this->assertFalse($target->fresh()->is_admin);

        $this->patch('/admin/admins/'.$target->id, ['is_admin' => false, 'reason' => 'Repeated removal should do nothing.'])->assertRedirect();
        $this->assertSame(2, AdminSystemAudit::count());
        $this->patch('/admin/admins/'.$admin->id, ['is_admin' => false, 'reason' => 'Invalid short reason'])->assertForbidden();
        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_public_announcement_follows_the_selected_locale_and_legacy_text_is_not_exposed(): void
    {
        PlatformSetting::updateOrCreate(['key' => 'announcement'], ['value' => 'Language unknown legacy announcement']);
        PlatformSetting::updateOrCreate(['key' => 'announcement_sq'], ['value' => 'Njoftim në shqip']);
        PlatformSetting::updateOrCreate(['key' => 'announcement_en'], ['value' => 'Notice in English']);

        $this->withCookie('rezervogk_locale', 'sq')->get('/')
            ->assertInertia(fn ($page) => $page->where('platformAnnouncement', 'Njoftim në shqip'));
        $this->withCookie('rezervogk_locale', 'en')->get('/')
            ->assertInertia(fn ($page) => $page->where('platformAnnouncement', 'Notice in English'));

        PlatformSetting::whereKey('announcement_en')->delete();
        $this->withCookie('rezervogk_locale', 'en')->get('/')
            ->assertInertia(fn ($page) => $page->where('platformAnnouncement', ''));
        $this->assertSame('Language unknown legacy announcement', PlatformSetting::value('announcement'));
    }

    public function test_admin_can_save_separate_public_announcement_languages(): void
    {
        $admin = User::factory()->create(['email' => 'bmalazezi@gmail.com', 'is_admin' => true]);

        $this->actingAs($admin)->patch('/admin/settings', [
            'registration_enabled' => true,
            'announcement_sq' => 'Njoftim shqip',
            'announcement_en' => 'English notice',
            'reason' => 'Publishing notices in both supported languages.',
        ])->assertRedirect();

        $this->assertSame('Njoftim shqip', PlatformSetting::value('announcement_sq'));
        $this->assertSame('English notice', PlatformSetting::value('announcement_en'));
    }

    public function test_non_admin_cannot_grant_access_and_short_reasons_are_rejected(): void
    {
        $ordinary = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($ordinary)->patch('/admin/admins/'.$target->id, ['is_admin' => true, 'reason' => 'Unauthorized permission change'])->assertForbidden();

        $admin = User::factory()->create(['email' => 'bmalazezi@gmail.com', 'is_admin' => true]);
        $this->actingAs($admin)->patch('/admin/admins/'.$target->id, ['is_admin' => true, 'reason' => 'short'])->assertSessionHasErrors('reason');
        $this->assertFalse($target->fresh()->is_admin);
        $this->assertDatabaseCount('admin_system_audits', 0);
    }

    public function test_admin_can_search_for_eligible_accounts_outside_the_recent_candidate_window(): void
    {
        $admin = User::factory()->create(['email' => 'bmalazezi@gmail.com', 'is_admin' => true]);
        $older = User::factory()->create(['email' => 'older-candidate@example.test']);
        User::factory()->count(101)->create();

        $this->actingAs($admin)->get('/admin/settings?candidate_search=older-candidate@example.test')
            ->assertOk()->assertInertia(fn ($page) => $page->component('AdminSystem')
                ->where('candidateSearch', 'older-candidate@example.test')
                ->where('candidates.0.id', $older->id)
                ->where('candidates.0.email', $older->email));
    }
}
