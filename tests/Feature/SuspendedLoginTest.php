<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspendedLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secure-Password-123!';
    private const SUSPENDED_SQ = 'Llogaria juaj është pezulluar. Ju lutemi kontaktoni mbështetjen nëse mendoni se ky është një gabim.';

    public function test_active_user_can_login_and_bad_password_keeps_generic_error(): void
    {
        $user = User::factory()->create(['email' => 'active@example.test', 'password' => self::PASSWORD]);
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-password-123!'])
            ->assertSessionHasErrors(['email' => __('Email-i ose fjalëkalimi nuk është i saktë.')]);
        $this->assertGuest();
    }

    public function test_suspended_user_gets_dedicated_message_only_with_correct_password(): void
    {
        $user = User::factory()->create(['email' => 'suspended@example.test', 'password' => self::PASSWORD, 'is_suspended' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-password-123!'])
            ->assertSessionHasErrors(['email' => __('Email-i ose fjalëkalimi nuk është i saktë.')]);
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => self::SUSPENDED_SQ]);
        $this->assertGuest();
    }

    public function test_admin_can_suspend_reactivate_and_user_can_login_after_reactivation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'toggle@example.test', 'password' => self::PASSWORD]);
        $this->actingAs($admin)->patch('/admin/users/'.$user->id.'/suspension', ['suspended' => true, 'reason' => 'Testing suspension behavior'])
            ->assertRedirect();
        $this->assertTrue($user->fresh()->is_suspended);
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors(['email' => self::SUSPENDED_SQ]);
        $this->actingAs($admin)->patch('/admin/users/'.$user->id.'/suspension', ['suspended' => false, 'reason' => 'Testing account reactivation'])
            ->assertRedirect();
        $this->assertFalse($user->fresh()->is_suspended);
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }
}
