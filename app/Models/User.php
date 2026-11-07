<?php

namespace App\Models;

use App\Support\TransactionalMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const SUPER_ADMIN_EMAIL = 'bmalazezi@gmail.com';
    public const CURRENT_POLICY_VERSION = '2026-10-29';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(TransactionalMail::class)->sendReset($this, $token);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function manualPayments(): HasMany
    {
        return $this->hasMany(ManualPayment::class);
    }

    public function goalkeeperProfile(): HasOne
    {
        return $this->hasOne(GoalkeeperProfile::class);
    }

    public function hasSuperAdminIdentity(): bool
    {
        return hash_equals(self::SUPER_ADMIN_EMAIL, mb_strtolower(trim((string) $this->email)));
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_admin && $this->hasSuperAdminIdentity();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'accepted_terms_version',
        'accepted_terms_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'trial_started_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_suspended' => 'boolean',
            'accepted_terms_at' => 'immutable_datetime',
        ];
    }
}
