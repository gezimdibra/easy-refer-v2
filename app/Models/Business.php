<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A tenant business (spec Section 7.2). Every tenant-owned record in the
 * system carries a business_id; this model is the root of that scoping.
 *
 * @use HasFactory<BusinessFactory>
 */
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'contact_email',
        'contact_phone_e164',
        'country',
        'street',
        'city',
        'state',
        'postal_code',
        'timezone',
        'status',
        'friend_reward_text',
        'referrer_reward_text',
        'redemption_window_days',
        'daily_referral_cap',
        'monthly_referral_cap',
        'square_customer_id',
        'billing_status',
        'current_plan_id',
        'free_visits_remaining',
        'email_verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'redemption_window_days' => 'integer',
            'daily_referral_cap' => 'integer',
            'monthly_referral_cap' => 'integer',
            'free_visits_remaining' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'owner');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
