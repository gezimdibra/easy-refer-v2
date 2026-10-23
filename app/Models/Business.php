<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * The business types offered at signup Step 1 (spec Section 4.1).
     *
     * @var list<string>
     */
    public const TYPES = ['barber', 'salon', 'cafe', 'boutique', 'other'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'business_type',
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
        'average_ticket_cents',
        'google_review_url',
        'owner_text_notifications',
        'acq_source',
        'acq_medium',
        'acq_campaign',
        'acq_term',
        'acq_content',
        'acq_gclid',
        'acq_fbclid',
        'acq_ttclid',
        'acq_landing_path',
        'acq_referrer_host',
        'acq_captured_at',
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
            'current_plan_id' => 'integer',
            'average_ticket_cents' => 'integer',
            'owner_text_notifications' => 'boolean',
            'acq_captured_at' => 'datetime',
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

    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'memberships')
            ->withPivot(['share_token_hash', 'share_token_state', 'sms_consent'])
            ->withTimestamps();
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    public function referralEvents(): HasMany
    {
        return $this->hasMany(ReferralEvent::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    public function billingEvents(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(BusinessAlert::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether the public referral flow is live (spec Section 9.9):
     * the flow activates only once a card is on file, which flips
     * billing_status from setup_incomplete to active at onboarding
     * (Phase 4), and stays live through past_due grace.
     */
    public function flowIsActive(): bool
    {
        return $this->isActive()
            && in_array($this->billing_status, ['active', 'past_due'], true);
    }

    /**
     * The business's current live QR code, if one exists.
     */
    public function activeQrCode(): ?QrCode
    {
        return $this->qrCodes()->where('state', 'active')->latest('id')->first();
    }
}
