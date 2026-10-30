<?php

namespace Tests\Feature;

use App\Models\AdminSystemAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_designation_command_grants_only_matching_account_and_is_idempotent(): void
    {
        $superAdmin = User::factory()->create(['email' => ' BmALaZezi@Gmail.com ', 'is_admin' => false]);
        $other = User::factory()->create(['email' => 'someone-else@example.test', 'is_admin' => false]);

        $this->artisan('admin:designate-super-admin', ['--reason' => 'Initial administrator designation.'])->assertSuccessful();
        $this->assertTrue($superAdmin->fresh()->is_admin);
        $this->assertFalse($other->fresh()->is_admin);
        $this->assertDatabaseHas('admin_system_audits', [
            'target_id' => $superAdmin->id, 'action' => 'super_admin_designated', 'actor_id' => null,
        ]);

        $this->artisan('admin:designate-super-admin', ['--reason' => 'Repeat designation safely.'])->assertSuccessful();
        $this->assertSame(1, AdminSystemAudit::where('action', 'super_admin_designated')->count());
    }

    public function test_designation_command_does_not_create_an_account_when_identity_is_missing(): void
    {
        User::factory()->create(['email' => 'another@example.test', 'is_admin' => false]);
        $this->artisan('admin:designate-super-admin', ['--reason' => 'No account should be created.'])->assertFailed();
        $this->assertSame(1, User::count());
        $this->assertSame(0, AdminSystemAudit::count());
    }

    public function test_only_designated_super_admin_can_manage_admin_access(): void
    {
        $superAdmin = User::factory()->create(['email' => 'bmalazezi@gmail.com', 'is_admin' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();

        $this->actingAs($admin)->patch('/admin/admins/'.$target->id, [
            'is_admin' => true, 'reason' => 'This administrator tries to grant access.',
        ])->assertForbidden();
        $this->assertFalse($target->fresh()->is_admin);

        $this->actingAs($superAdmin)->patch('/admin/admins/'.$target->id, [
            'is_admin' => true, 'reason' => 'Grant access for moderation work.',
        ])->assertRedirect();
        $this->assertTrue($target->fresh()->is_admin);

        $this->actingAs($superAdmin)->patch('/admin/admins/'.$superAdmin->id, [
            'is_admin' => false, 'reason' => 'Attempt to demote protected account.',
        ])->assertForbidden();
        $this->assertTrue($superAdmin->fresh()->is_admin);

        $this->actingAs($admin)->get('/admin/settings')->assertForbidden();
    }

    public function test_super_admin_identity_cannot_be_suspended_or_deleted_even_if_admin_flag_is_missing(): void
    {
        $superAdmin = User::factory()->create(['email' => 'BMALAZEZI@GMAIL.COM', 'is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch('/admin/users/'.$superAdmin->id.'/suspension', [
            'suspended' => true, 'reason' => 'Attempt to suspend designated administrator.',
        ])->assertForbidden();
        $this->assertFalse($superAdmin->fresh()->is_suspended);

        $this->actingAs($superAdmin)->delete('/account', ['confirmation' => '1'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
    }

    public function test_other_admin_cannot_become_super_admin_by_email_or_claimed_flag(): void
    {
        $ordinaryAdmin = User::factory()->create(['email' => 'other-admin@example.test', 'is_admin' => true]);
        $this->assertFalse($ordinaryAdmin->isSuperAdmin());
        $this->actingAs($ordinaryAdmin)->patch('/admin/admins/'.$ordinaryAdmin->id, [
            'is_admin' => false, 'reason' => 'Self demotion attempt should be rejected.',
        ])->assertForbidden();
        $this->assertTrue($ordinaryAdmin->fresh()->is_admin);
    }
}
