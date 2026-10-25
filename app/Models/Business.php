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
        'reward_vesting_days',
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
        'weekly_digest_opt_out',
        'nudge_opt_out',
        'checklist_dismissed_at',
        'closed_at',
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
            'reward_vesting_days' => 'integer',
            'daily_referral_cap' => 'integer',
            'monthly_referral_cap' => 'integer',
            'free_visits_remaining' => 'integer',
            'current_plan_id' => 'integer',
            'average_ticket_cents' => 'integer',
            'owner_text_notifications' => 'boolean',
            'weekly_digest_opt_out' => 'boolean',
            'nudge_opt_out' => 'boolean',
            'checklist_dismissed_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * An instant as the business's wall clock reads it (T2-F4):
     * owner-facing event times render in the business's timezone,
     * never bare UTC. A missing or unrecognized stored zone falls
     * back to the app timezone rather than failing the render.
     */
    public function localTime(\Illuminate\Support\Carbon $when): \Illuminate\Support\Carbon
    {
        $zone = is_string($this->timezone) && in_array($this->timezone, timezone_identifiers_list(), true)
            ? $this->timezone
            : (string) config('app.timezone');

        return $when->copy()->setTimezone($zone);
    }

    /**
     * A closed business (spec Section 5.5 closure) keeps its owner
     * login in restricted, read-only mode for the 30-day wind-down;
     * a disabled business (platform suspension, Section 15) is a
     * different state with a different owner experience.
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Whether the 30-day closure wind-down (spec Section 5.5) has
     * run out: minted rewards stayed claimable for exactly 30 days
     * after closure; past that they expire and their claim links
     * answer with the generic page (RDM-29, second leg).
     */
    public function closureWindowEnded(): bool
    {
        return $this->isClosed()
            && $this->closed_at !== null
            && $this->closed_at->copy()->addDays(30)->isPast();
    }

    /**
     * The R1 reward headline ("Get {reward} on your first visit"),
     * composed so the first-visit idea can never appear twice.
     * A reward text that already carries it ("$10 off your first
     * cut", "10 percent off your first visit") stands alone after
     * "Get": the suffix is appended only when the reward never
     * mentions a first anything. One composer serves every
     * surface that runs the headline (the flow landing today,
     * the printed poster through friendRewardHeadlineSuffix()),
     * so the wording cannot drift between them (F-2).
     */
    public function friendRewardHeadline(): ?string
    {
        $reward = trim((string) $this->friend_reward_text);

        if ($reward === '') {
            return null;
        }

        $suffix = $this->friendRewardHeadlineSuffix();

        if ($suffix !== '') {
            $reward = rtrim($reward, '.');
        }

        return 'Get '.$reward.$suffix;
    }

    /**
     * The headline's suffix: " on your first visit" when the
     * reward text does not already say it, empty when it does.
     * A trailing period on the reward is headline punctuation
     * the suffix replaces, so it is dropped before appending.
     */
    public function friendRewardHeadlineSuffix(): string
    {
        $reward = trim((string) $this->friend_reward_text);

        if ($reward === '' || preg_match('/\bfirst\b/i', $reward) === 1) {
            return '';
        }

        return ' on your first visit';
    }

    /**
     * The printed poster's pass-2 line (QR growth kit, copy
     * REF-POSTER-02a): the scanner's own payoff beside the
     * friend's, rendered from BOTH reward texts through this
     * one composer so every print artifact phrases it
     * identically. The friend reward stays the headline; this
     * line answers "what do I get" for the person holding the
     * phone. Each text is trimmed and stripped of a trailing
     * period, and the "Give" side derives from the friend
     * reward's leading figure (the poster's own extraction),
     * so it never prints a whole sentence.
     */
    /**
     * One reward text, normalized for composition: trimmed, a
     * trailing period stripped, then trimmed again — owners
     * type "A free coffee ." with a space before the period,
     * and the space the strip leaves behind must not reach
     * the line (F-11: it printed "Give A free coffee . Get ..."
     * and doubled the space before "when" on the Get side).
     */
    private static function rewardTextForLine(?string $text): string
    {
        return trim(rtrim(trim((string) $text), '.'));
    }

    public function giveGetLine(): string
    {
        $friend = self::rewardTextForLine($this->friend_reward_text);
        $referrer = self::rewardTextForLine($this->referrer_reward_text);

        if ($friend !== '' && $referrer !== '') {
            return 'Give '.$this->giveFigure().'. Get '.$referrer.' when your friend visits.';
        }

        if ($friend !== '') {
            return 'Share it with a friend. You get a reward when they visit.';
        }

        if ($referrer !== '') {
            return 'Your friend gets a reward on their first visit. You get '.$referrer.' when they visit.';
        }

        return 'Scan, share, and you both get rewarded when your friend visits.';
    }

    /**
     * The "Give" amount: the friend reward's leading figure
     * ("$10", "20%", "10 percent"), plus the word "off" when
     * the text carries it ("$10 off"). A reward with no
     * leading figure gives its whole short text ("A free
     * coffee").
     */
    private function giveFigure(): string
    {
        $friend = self::rewardTextForLine($this->friend_reward_text);

        if (preg_match('/^(\$?\d+(?:[.,]\d+)?(?:\s*(?:%|percent\b))?)/i', $friend, $match) === 1) {
            $rest = substr($friend, strlen($match[1]));

            return preg_match('/^\s*off\b/i', $rest) === 1 ? $match[1].' off' : $match[1];
        }

        return $friend;
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
