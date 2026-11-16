<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminExportController;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\CreateDemoUsersSeeder;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite()->seed([GoalkeeperDemoSeeder::class, CreateDemoUsersSeeder::class]);
        $this->owner = User::factory()->create(['email' => User::SUPER_ADMIN_EMAIL, 'is_admin' => true]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function keeper(): User
    {
        return GoalkeeperProfile::publiclyVisible()->firstOrFail()->user;
    }

    public function test_any_admin_can_send_a_password_reset_link(): void
    {
        Notification::fake();
        config(['transactional-mail.enabled' => true, 'mail.default' => 'array', 'mail.from.address' => 'no-reply@gjejportier.online', 'app.url' => 'https://gjejportier.online']);
        $keeper = $this->keeper();

        $this->actingAs($this->admin)->post("/admin/users/{$keeper->id}/password-reset")->assertRedirect()->assertSessionHas('status');
        Notification::assertSentTo($keeper, ResetPassword::class);
        $this->assertDatabaseHas('admin_user_audits', ['target_id' => $keeper->id, 'action' => 'password_reset_sent', 'actor_id' => $this->admin->id]);
        $this->flushSession();
        $this->actingAs($keeper)->post("/admin/users/{$keeper->id}/password-reset")->assertForbidden();
    }

    public function test_only_the_main_admin_deletes_accounts_with_reason_and_typed_email(): void
    {
        $keeper = $this->keeper();
        $this->actingAs($this->admin)->get("/admin/users/{$keeper->id}")->assertInertia(fn (Assert $page) => $page->where('canDelete', false));
        $this->actingAs($this->admin)->delete("/admin/users/{$keeper->id}", ['reason' => 'Spam account reported twice.', 'confirm_email' => $keeper->email])->assertForbidden();

        $this->actingAs($this->owner)->get("/admin/users/{$keeper->id}")->assertInertia(fn (Assert $page) => $page->where('canDelete', true));
        $this->actingAs($this->owner)->delete("/admin/users/{$keeper->id}", ['reason' => 'Spam account reported twice.', 'confirm_email' => 'wrong@example.test'])->assertSessionHasErrors('confirm_email');
        $this->assertDatabaseHas('users', ['id' => $keeper->id]);

        $this->actingAs($this->owner)->delete("/admin/users/{$keeper->id}", ['reason' => 'Spam account reported twice.', 'confirm_email' => strtoupper($keeper->email)])->assertRedirect('/admin/users');
        $this->assertDatabaseMissing('users', ['id' => $keeper->id]);
        $this->assertDatabaseHas('admin_system_audits', ['action' => 'account_deleted', 'actor_id' => $this->owner->id]);
    }

    public function test_admins_and_the_owner_cannot_be_deleted_from_the_panel(): void
    {
        $this->actingAs($this->owner)->delete("/admin/users/{$this->admin->id}", ['reason' => 'Trying to delete an admin.', 'confirm_email' => $this->admin->email])->assertForbidden();
        $this->actingAs($this->owner)->delete("/admin/users/{$this->owner->id}", ['reason' => 'Trying to delete myself.', 'confirm_email' => $this->owner->email])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_health_page_reports_backup_mail_scheduler_and_disk(): void
    {
        $status = tempnam(sys_get_temp_dir(), 'backup');
        file_put_contents($status, json_encode(['finished_at' => now()->subHours(3)->toIso8601String(), 'bytes' => 75000, 'kept_days' => 3]));
        config(['admin-security.backup_status_file' => $status]);
        \Illuminate\Support\Facades\Cache::forever('admin:scheduler-heartbeat', now()->getTimestamp());

        $this->actingAs($this->admin)->get('/admin/health')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/health')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminHealth')
            ->where('checks.backup.ok', true)->where('checks.backup.keptDays', 3)
            ->where('checks.scheduler.ok', true)->has('checks.disk.usedPercent')->has('checks.mail.ok')->has('version.php'));

        file_put_contents($status, json_encode(['finished_at' => now()->subDays(3)->toIso8601String()]));
        $this->actingAs($this->owner)->get('/admin/health')->assertInertia(fn (Assert $page) => $page->where('checks.backup.ok', false));
        unlink($status);
    }

    public function test_csv_exports_are_owner_only_audited_and_formula_safe(): void
    {
        $this->actingAs($this->admin)->get('/admin/export/users.csv')->assertForbidden();
        GoalkeeperProfile::query()->first()->forceFill(['name' => '=HYPERLINK("http://x")'])->save();

        $users = $this->actingAs($this->owner)->get('/admin/export/users.csv');
        $users->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('id,name,email,registered_at', $users->streamedContent());
        $this->assertStringContainsString($this->owner->email, $users->streamedContent());

        $keepers = $this->actingAs($this->owner)->get('/admin/export/goalkeepers.csv')->streamedContent();
        $this->assertStringContainsString('public_link', $keepers);
        $this->assertStringContainsString("'=HYPERLINK", $keepers);
        $this->assertStringNotContainsString(',=HYPERLINK', $keepers);
        $this->assertDatabaseHas('admin_system_audits', ['action' => 'export_users', 'actor_id' => $this->owner->id]);
        $this->assertDatabaseHas('admin_system_audits', ['action' => 'export_goalkeepers']);
        $this->assertSame("'-5", AdminExportController::safe('-5'));
    }
}
