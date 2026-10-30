<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An owner account (spec Section 7.2). The password attribute is hashed
 * by the framework hasher on assignment; the configured driver is
 * Argon2id (config/hashing.php, spec Section 8.2). Two-factor columns
 * are schema-ready in Phase 1 (spec Section 8.6); enforcement policy is
 * OD-9.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'role',
        'name',
        'email',
        'canonical_email',
        'phone_e164',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'welcome_nudge_sent_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
            'two_factor_pending_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Reset emails go through the app notification, which is queued
     * so the reset endpoint's response timing cannot reveal whether
     * the account exists (spec Section 8.3, finding L-3).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * EU-S2: verification mail goes through the app notification
     * (the framework's VerifyEmail subclass on the custom shell).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmailNotification);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Two-factor is active only once an enrollment is confirmed
     * (spec Section 8.6); a stored secret alone is a pending setup.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function hasPendingTwoFactorEnrollment(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }
}
