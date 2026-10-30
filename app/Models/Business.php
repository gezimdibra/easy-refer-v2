<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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
    public const TYPES = ['barber', 'salon', 'cafe', 'restaurant', 'boutique', 'other'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'business_type',
        'is_demo',
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
        'reward_split',
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
        'slow_day_weekday',
        'slow_day_reminders_off',
        'weekly_digest_opt_out',
        'nudge_opt_out',
        'setup_reminders_opt_out',
        'checklist_dismissed_at',
        'onboarding_completed_at',
        'closed_at',
        'claim_source',
        'claimable_page_id',
        'signup_source',
        'signup_source_detail',
        'signup_source_captured_at',
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
            'is_demo' => 'boolean',
            'current_plan_id' => 'integer',
            'average_ticket_cents' => 'integer',
            'reward_split' => 'boolean',
            'owner_text_notifications' => 'boolean',
            'slow_day_weekday' => 'integer',
            'slow_day_reminders_off' => 'boolean',
            'weekly_digest_opt_out' => 'boolean',
            'nudge_opt_out' => 'boolean',
            'setup_reminders_opt_out' => 'boolean',
            'checklist_dismissed_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'closed_at' => 'datetime',
            'acq_captured_at' => 'datetime',
            'signup_source_captured_at' => 'datetime',
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

    public function couponBatches(): HasMany
    {
        return $this->hasMany(CouponBatch::class);
    }

    public function couponClaims(): HasMany
    {
        return $this->hasMany(CouponClaim::class);
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
     * The G6 demo business flag (Amendment 63): the ONE key
     * every demo exclusion reads (design pass Section 13). A
     * demo business is fictional, never chargeable, and absent
     * from discovery, counts, aggregates, and audiences; the
     * real customer flow treats it as not live, and its tokens
     * redeem only inside the demo guard.
     */
    public function isDemo(): bool
    {
        return (bool) $this->is_demo;
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
     * The R1 reward headline ("{reward} on your first visit"),
     * composed so the first-visit idea can never appear twice.
     * The headline is the reward itself, with no verb prefixed
     * (owner order 2026-10-27, release 35: the generated "Get "
     * prefix was removed; the reward reads as the offer, e.g.
     * "10 percent off your first visit", "Free coffee"). A reward
     * text that already carries the first-visit idea ("$10 off
     * your first cut", "10 percent off your first visit") stands
     * alone: the suffix is appended only when the reward never
     * mentions a first anything. One composer serves every
     * surface that runs the headline (the flow landing and the
     * redeem page today, the printed poster through
     * friendRewardHeadlineSuffix()), so the wording cannot drift
     * between them (F-2).
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

        return $reward.$suffix;
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

    /**
     * ONE-REWARD configuration (OR-1, one-reward-design-pass.md
     * §2): the configuration state of a business is the stored
     * flag ALONE — never column null-ness, never text equality.
     * UNIFIED (flag false, the default): one reward, both sides
     * receive it; canonical storage keeps the referrer column
     * NULL. SPLIT (flag true, the Settings opt-in): the referrer
     * receives the referrer text. SPLIT-EQUAL (flag true, texts
     * equal) is a real state and renders exactly as SPLIT
     * everywhere, because every surface branches on this flag.
     */
    public function rewardsAreSplit(): bool
    {
        return (bool) $this->reward_split;
    }

    /**
     * THE reward: the friend column, trimmed — the canonical
     * home of the one reward in every configuration (the
     * activation gate, the OG hash, and the poster fit pins all
     * already key on this column and keep their meaning).
     */
    public function rewardText(): ?string
    {
        $text = trim((string) $this->friend_reward_text);

        return $text === '' ? null : $text;
    }

    /**
     * The referrer's effective reward text (the OD-2 fallback,
     * now resolved in ONE place): SPLIT reads the referrer
     * column, defensively falling back to the reward if a split
     * row is ever non-canonical; UNIFIED reads the reward.
     * After this build, a direct read of referrer_reward_text
     * outside this model, the canonical writer, the Undo
     * snapshot, and the Settings form's own field prefill is a
     * defect.
     */
    public function effectiveReferrerRewardText(): ?string
    {
        if ($this->rewardsAreSplit()) {
            $referrer = trim((string) $this->referrer_reward_text);

            if ($referrer !== '') {
                return $referrer;
            }
        }

        return $this->rewardText();
    }

    /**
     * The ONE write path for reward configuration (§2.2/§2.4):
     * every surface that saves rewards (onboarding step,
     * dashboard card, Settings, pack prefills) routes through
     * here so the flag and the two columns can never drift
     * apart. Canonical forms: UNIFIED stores the reward in the
     * friend column, flag false, referrer NULL; SPLIT stores
     * both texts, flag true (a blank referrer under split falls
     * back to the reward rather than storing a lie); a blank
     * reward normalizes to UNSET (flag false, referrer NULL).
     */
    public function setRewardConfiguration(string $reward, bool $split, ?string $referrerReward = null): void
    {
        $reward = trim($reward);
        $referrerReward = $referrerReward !== null ? trim($referrerReward) : null;

        if ($reward === '') {
            $this->forceFill([
                'friend_reward_text' => null,
                'reward_split' => false,
                'referrer_reward_text' => null,
            ])->save();

            return;
        }

        if ($split) {
            $this->forceFill([
                'friend_reward_text' => $reward,
                'reward_split' => true,
                'referrer_reward_text' => ($referrerReward === null || $referrerReward === '') ? $reward : $referrerReward,
            ])->save();

            return;
        }

        $this->forceFill([
            'friend_reward_text' => $reward,
            'reward_split' => false,
            'referrer_reward_text' => null,
        ])->save();
    }

    public function giveGetLine(): string
    {
        $friend = self::rewardTextForLine($this->friend_reward_text);

        if ($friend === '') {
            // UNSET (ORS-05 scope): the shipped generic. The old
            // friend-only and referrer-only branches DIE here —
            // under canonical storage friend-only IS unified and
            // referrer-only is unreachable; leaving them would
            // keep a weaker, state-blind claim in the composer.
            return 'Scan, share, and you both get rewarded when your friend visits.';
        }

        if ($this->rewardsAreSplit()) {
            $referrer = self::rewardTextForLine($this->effectiveReferrerRewardText());

            return 'Give '.$this->giveFigure().'. Get '.$referrer.' when your friend visits.';
        }

        // UNIFIED (ORS-05): one reward, composed twice — the
        // Give side is what the friend receives, the Get side
        // is what the scanner-as-referrer receives; both are
        // the same stored value.
        return 'Give '.$this->giveFigure().'. Get '.$friend.' when your friend visits.';
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
     * The printed poster's ONE headline (owner ruling
     * 2026-10-29, Finding 4; Amendment 58 [REF-POSTER-04]):
     * the headline leads with the reward and the easy action
     * in the give/get pattern, and the words "join" and
     * "program" never appear in it — joining reads as a
     * commitment and the scan is a 3-second impulse. One
     * composer serves every print surface (counter, window,
     * mini, tent, receipt card + strip) and the QR landing
     * the code opens, so the poster's promise and the
     * landing's headline can never drift apart.
     *
     * Timing truth (COMPANY-KNOWLEDGE Section 4): both
     * sides' rewards are anchored to the friend's visit —
     * the friend's reward is given at that visit, and the
     * referrer's reward is earned at that visit (its
     * availability vests per OD-3, a state the flow's reward
     * pages state; the headline promises the earning, never
     * instant availability, and never uses a same-moment
     * word). The "when they visit" clause is what keeps the
     * sentence true in every configuration.
     *
     * UNIFIED: "Share with a friend, you both get {reward}
     * when they visit." SPLIT (the flag, per OR-1 — a
     * split-equal row composes the split form): "Give a
     * friend {friend reward}. Get {referrer reward} when
     * they visit." UNSET (no reward text yet — the state
     * the owner met on the production Poster step): the
     * unified shape with no amount named.
     */
    public function posterHeadline(): string
    {
        $friend = self::rewardTextForLine($this->friend_reward_text);

        if ($friend === '') {
            return 'Share with a friend, you both get rewarded when they visit.';
        }

        if ($this->rewardsAreSplit()) {
            $referrer = self::rewardTextForLine($this->effectiveReferrerRewardText());

            return 'Give a friend '.$friend.'. Get '.$referrer.' when they visit.';
        }

        return 'Share with a friend, you both get '.$friend.' when they visit.';
    }

    /**
     * The end of the current dunning grace window, or null when
     * the business is not past_due or its episode cannot be
     * dated. This is the ONE derivation every past-due surface
     * and the dunning emails read (BillingCopy::statusLine,
     * dunningPausedAt, flowIsActive, DunningService).
     *
     * The ONLY episode anchor is the webhook handler's
     * billing.past_due audit row: SquareWebhookHandler writes
     * it in the same transaction as the status flip, targeted
     * at the exact failed record, so its created_at IS the
     * episode start and the window is the config grace.
     *
     * There is deliberately NO failed-record fallback (two-key
     * re-review Finding 1, 2026-10-30, reversing the FLOW-STATE
     * TRUTH train's first shape and P1's identical fallback on
     * main): failed BillingRecords are also written by
     * MonthlyCharger and PendingRecordSweeper, which never set
     * past_due and never write the audit row, so "the latest
     * failed record" is not bound to this episode — a later
     * unrelated failure would fabricate a future grace end and
     * keep the code on past the real end (the SA-01 outcome on
     * a new anchor), and a stale earlier failure would pause
     * the business on day one. The record also carries no
     * failed-at field: created_at is its pending creation
     * (before the Square call) and updated_at is merely its
     * last save, so neither timestamp can date an episode.
     * Binding a fallback to the episode is impossible without
     * the audit row — the row's target and payment id are the
     * only binding that exists — so an audit-missing episode
     * FAILS CLOSED: null here, paused in dunningPausedAt(),
     * and DunningService sends nothing for it (it logs the
     * divergence Critical, as before). No surface may assert
     * a dated grace the email half treats as undatable.
     */
    /** Memoized grace end for this instance (SA-01 perf leg). */
    private bool $dunningGraceResolved = false;

    private ?\Illuminate\Support\Carbon $dunningGraceCache = null;

    public function dunningGraceEndsAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->billing_status !== 'past_due') {
            return null;
        }

        // SA-01 (Amendment 35): the dated past-due line is read
        // by several surfaces in one request (the banner or
        // gate partial and the Action Center read model, on
        // the same instance through the auth user). The episode
        // cannot change mid-request, so the audit read happens
        // once per instance; the dashboard's query budget
        // (DashboardPerformanceTest) counts the difference.
        if ($this->dunningGraceResolved) {
            return $this->dunningGraceCache?->copy();
        }

        $episode = AuditLog::query()
            ->where('business_id', $this->id)
            ->where('action', 'billing.past_due')
            ->orderByDesc('id')
            ->first();

        $episodeStart = $episode?->created_at;

        $this->dunningGraceResolved = true;
        $this->dunningGraceCache = $episodeStart === null
            ? null
            : $episodeStart->copy()->addDays((int) config('billing.dunning_grace_days', 7));

        return $this->dunningGraceCache?->copy();
    }

    /**
     * FE-CANCEL-01: whether money is still owed on this
     * business: the settlement walk's unclaimed billable total
     * (visits plus attempted texts, all open months) is above
     * zero, or a charge record is still pending resolution.
     * This is the one owed read the closed card-replace gate,
     * the closed dunning mails, and the closed Billing page
     * branch on, so they can never disagree about whether a
     * balance exists.
     */
    public function hasOutstandingBillingBalance(): bool
    {
        // The unclaimed forecast only: a PENDING record is a
        // charge still confirming (its events are claimed, so
        // the forecast is zero), and during that window there
        // is no replaceable balance yet. When the record fails,
        // its events are released and this read turns true.
        $forecast = (new \App\Services\Billing\SettlementService)->forecastPreview($this);

        return $forecast['total_cents'] > 0;
    }

    /**
     * FE-CANCEL-01 (E-07): the past-due episode's amount and
     * month, read exactly as DunningService reads them: the
     * billing.past_due audit row's target record when it names
     * one of this business's records, else the latest failed
     * record. Null when no episode record exists (the logged
     * divergence): surfaces then render no figure at all
     * rather than inventing one.
     *
     * @return array{amount_cents: int, month: string}|null
     */
    public function pastDueEpisode(): ?array
    {
        if ($this->billing_status !== 'past_due') {
            return null;
        }

        $episode = AuditLog::query()
            ->where('business_id', $this->id)
            ->where('action', 'billing.past_due')
            ->orderByDesc('id')
            ->first();

        $record = null;

        if ($episode !== null && $episode->target_type === BillingRecord::class && $episode->target_id !== null) {
            $candidate = BillingRecord::query()->find($episode->target_id);
            $record = ($candidate !== null && $candidate->business_id === $this->id) ? $candidate : null;
        }

        $record ??= BillingRecord::query()
            ->where('business_id', $this->id)
            ->where('state', BillingRecord::STATE_FAILED)
            ->orderByDesc('id')
            ->first();

        if ($record === null) {
            return null;
        }

        $anchor = $record->period_start ?? $record->created_at;

        return [
            'amount_cents' => (int) $record->amount_cents,
            'month' => $anchor !== null ? \Illuminate\Support\Carbon::parse($anchor)->format('F Y') : '',
        ];
    }

    /**
     * Whether the promised dunning pause is in effect at the
     * given instant: past_due and the grace window has closed —
     * or past_due with no datable episode at all, which fails
     * closed: a business that cannot prove grace remains does
     * not get an open-ended "keeps working" (FLOW-STATE TRUTH
     * train; the undated-never-pauses rule is retired).
     */
    public function dunningPausedAt(?\Illuminate\Support\Carbon $at = null): bool
    {
        if ($this->billing_status !== 'past_due') {
            return false;
        }

        $graceEnd = $this->dunningGraceEndsAt();

        if ($graceEnd === null) {
            return true;
        }

        return ($at ?? now())->greaterThanOrEqualTo($graceEnd);
    }

    /**
     * Whether the owner has set a typical spend per visit
     * (P1 SPEND-DEFAULT, critic report 2026-10-29; Amendment
     * 57 family). Unset is NULL or 0: the onboarding Finish
     * screen already treats a calculator-carried 0 as unset
     * (RS2-3), and a stored 0 is no figure at all. Every
     * surface that renders the figure or an estimate built
     * from it branches on this one definition, so a default
     * or a zero can never be dressed as the owner's own
     * number again.
     */
    public function hasTypicalSpend(): bool
    {
        return (int) $this->average_ticket_cents > 0;
    }

    /**
     * The RENDERING split on the billing side (two-key
     * re-review Finding 2, 2026-10-30; billingAllowsFlow()
     * retired in the activation merge, 2026-10-30): the gate
     * lumps two states whose remedies differ, so surfaces that
     * name the remedy must branch on these, never on the lumped
     * predicate alone.
     *
     * - No-card: billing_status none / setup_incomplete. Adding
     *   a card flips the business to active
     *   (CardOnFileService::applySavedCard), so "add a card and
     *   your code turns on" is true here.
     * - Past-due paused: past_due past its grace end (or with
     *   an undatable episode, which fails closed). Saving a
     *   card does NOT unpause this state — applySavedCard only
     *   flips none/setup_incomplete — the business returns only
     *   via a successful charge applied by
     *   SquareWebhookHandler, so the paused truth is the only
     *   true remedy sentence.
     */
    public function billingIsNoCard(): bool
    {
        return in_array($this->billing_status, ['none', 'setup_incomplete'], true);
    }

    public function billingIsPastDuePaused(): bool
    {
        return $this->billing_status === 'past_due' && $this->dunningPausedAt();
    }

    /**
     * Whether the business has stated the friend reward (Train
     * F2, finding F-2): the trimmed friend text is non-empty.
     * This is the ONE definition — the setup checklist, the
     * starter-pack card, and the activation gate below all read
     * it, so a whitespace-only text counts as unset everywhere
     * (a blank text would otherwise mint a blank reward
     * snapshot at redemption). The referrer text stays optional
     * (OD-2: it falls back to the friend text).
     */
    public function hasFriendRewardText(): bool
    {
        return trim((string) $this->friend_reward_text) !== '';
    }

    /** Memoized card fact for this instance (the gate reads it
     * on many surfaces in one request; the card cannot change
     * mid-request — the same convention as the dunning grace
     * memoization above). */
    private ?bool $cardOnFileCache = null;

    /**
     * Whether a succeeded default payment method exists (the
     * SetupChecklist G2 definition of a card on file). Never
     * billing_status (a card-removed business keeps 'active')
     * and never square_customer_id (stored before the card
     * verifies; a failed save leaves it set over a dead flow).
     */
    public function hasCardOnFile(): bool
    {
        return $this->cardOnFileCache ??= PaymentMethod::defaultFor($this) !== null;
    }

    /** Memoized allowance count for this instance (see above). */
    private ?int $allowanceUsedCache = null;

    /**
     * How many of the one-time first-visits allowance the
     * settlement walk would settle as free_credit if it ran
     * now (activation redesign, owner ruling 2026-10-30;
     * design pass Section 2.2): the count of BillingEvent
     * PARTICIPANTS — coverage not held, not fraud_denied, not
     * refunded, the three outcomes VisitValuation branches on
     * before the free-credit branch — capped at the allowance.
     * The gate and every printed free-visits-left number read
     * THIS count, never the free_visits_remaining column (the
     * column is settlement-lagged: between the 5th redemption
     * and the next batch it still reads 1 while the allowance
     * is spent) and never referral states (the ledger is the
     * count of visits that count).
     */
    public function allowanceUsed(): int
    {
        if ($this->allowanceUsedCache === null) {
            $allowance = (int) config('billing.free_visits_at_signup', 5);

            $this->allowanceUsedCache = min($allowance, BillingEvent::query()
                ->where('business_id', $this->id)
                ->whereNotIn('coverage', ['held', 'fraud_denied', 'refunded', 'demo', 'written_off'])
                ->count());
        }

        return $this->allowanceUsedCache;
    }

    /** Free visits left in the one-time allowance (Section 2.2). */
    public function allowanceLeft(): int
    {
        return max(0, (int) config('billing.free_visits_at_signup', 5) - $this->allowanceUsed());
    }

    /**
     * Whether the public referral flow is live (spec Section 9.9,
     * as redesigned by the owner ruling of 2026-10-30):
     * activation = status active + friend reward set + not
     * dunning-paused + (a card on file OR free allowance left).
     * Train F2's reward leg stands (a flow live without the
     * reward text stranded the friend at the counter when the
     * redemption could not mint a reward from texts that were
     * never set). The card leg is replaced by the allowance
     * leg: a business with NO card is live from signup for
     * exactly its first 5 free visits; when the 5th counted
     * visit lands the code pauses until a card is added, and
     * removing the only card re-enters the same logic.
     * billing_status is NOT an activation input: it remains a
     * billing-collection state, read by the charger, the
     * dunning machinery, and billing surfaces — and by this
     * gate only through the past_due test of the dunning leg.
     * The flow stays live through past_due grace; when the
     * grace window closes, the pause DUN-04 and DUN-05 promised
     * on a named date happens here (money fix train, audit 3.1).
     * Staff redemption is never gated by this predicate or by
     * any pause (design pass Section 4.2).
     */
    public function flowIsActive(): bool
    {
        // The demo business (Amendment 63) is never a live flow:
        // its only door is the demo guard. Stating it here, in
        // the ONE definition every surface reads, keeps the real
        // customer flow (landing, enrollment, claim, OG cards)
        // from ever opening at a fictional shop.
        if ($this->isDemo()) {
            return false;
        }

        if (! $this->isActive()) {
            return false;
        }

        if (! $this->hasFriendRewardText()) {
            return false;
        }

        if ($this->billing_status === 'past_due' && $this->dunningPausedAt()) {
            return false;
        }

        return $this->hasCardOnFile() || $this->allowanceLeft() > 0;
    }

    /**
     * Why the flow is off, or null when it is live (activation
     * redesign, design pass Section 2.4): the FIRST failing leg
     * in the order every owner surface names remedies in —
     * status, then the dunning pause, then the reward text
     * (the shipped F2 dependency order: rewards precede card),
     * then the allowance. Friend-facing surfaces branch only
     * on 'allowance_exhausted' (its own paused page); all other
     * causes keep the shipped pages.
     */
    public function flowBlockReason(): ?string
    {
        if ($this->flowIsActive()) {
            return null;
        }

        // The demo business (activation merge fix, 2026-10-30):
        // flowIsActive() already refuses it outright; its block
        // reason is the status bucket, never a money leg — a
        // fictional shop must never render the allowance-paused
        // friend page (DemoPhase1Test A08 pins the generic
        // not-active page on its placements).
        if ($this->isDemo()) {
            return 'not_active_status';
        }

        if (! $this->isActive()) {
            return 'not_active_status';
        }

        if ($this->billing_status === 'past_due' && $this->dunningPausedAt()) {
            return 'dunning_paused';
        }

        if (! $this->hasFriendRewardText()) {
            return 'reward_text_missing';
        }

        return 'allowance_exhausted';
    }

    /**
     * Whether the coupon channel accepts NEW unlocks (ID-11,
     * design pass Section 2.4): a DISTINCT predicate from
     * flowIsActive, sharing its legs and replacing one. The
     * friend reward text leg does NOT apply, because a batch
     * carries its own offer snapshot, so the F-2 failure the
     * text leg prevents cannot occur on this path. Every other
     * leg is flowIsActive's own, as redesigned by the owner
     * ruling of 2026-10-30: status active, not dunning-paused,
     * and a card on file OR free allowance left. That ruling
     * supersedes the pass's pre-redesign billing leg (a batch
     * born behind a card wall): a no-card business is live for
     * coupons exactly as it is live for the referral code,
     * because coupon claims never bill and never consume the
     * allowance, so the allowance leg here gates only on the
     * business's standing, never on coupon use. Ready claims
     * are never gated by this predicate: the pause gates new
     * promises, never already-earned ones, and closure honours
     * ready claims through its 30-day window (the claim
     * model's closure expiry applies that end). Nothing keyed
     * on flowIsActive changes meaning because this exists.
     */
    public function couponFlowActive(): bool
    {
        if ($this->isDemo()) {
            return false;
        }

        if (! $this->isActive()) {
            return false;
        }

        if ($this->billing_status === 'past_due' && $this->dunningPausedAt()) {
            return false;
        }

        return $this->hasCardOnFile() || $this->allowanceLeft() > 0;
    }

    /**
     * Why coupons are off for new unlocks, or null when they
     * are on (ID-11, pass Section 2.4): the coupon twin of
     * flowBlockReason, minus the reward-text leg, so the
     * Coupons tab banner (COUP-12) can name the true remedy
     * for the state the business is actually in.
     */
    public function couponFlowBlockReason(): ?string
    {
        if ($this->couponFlowActive()) {
            return null;
        }

        if ($this->isClosed()) {
            return 'closed';
        }

        if ($this->billing_status === 'past_due' && $this->dunningPausedAt()) {
            return 'dunning_paused';
        }

        return 'not_active';
    }

    /**
     * The business's current live QR code, if one exists.
     */
    /**
     * The business's single representative code for the surfaces
     * that predate placements (dashboard card, checklist, the QR
     * page's headline code). QRK-05 fallback chain: the active
     * counter code first (the launch placement), then a legacy
     * code with no placement, then any active code, so a business
     * is never codeless while any placement is live.
     */
    /**
     * The business's short-link slug (QRK-06), the typed address
     * printed on every poster (REF-POSTER-02j). Generated ONCE
     * from the business name, uniquified against every other
     * business, stored, and never regenerated: a rename keeps
     * the slug, so printed posters keep resolving for the life
     * of the business. Uniqueness is an application-level
     * guarantee (the column predates the feature and carries no
     * unique index; changing that is outside this train's one
     * migration).
     */
    public function ensureJoinSlug(): string
    {
        if (is_string($this->slug) && $this->slug !== '') {
            return $this->slug;
        }

        $base = Str::slug($this->name) ?: 'store';
        $slug = $base;
        $suffix = 2;
        while (static::query()->where('slug', $slug)->whereKeyNot($this->getKey())->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        $this->forceFill(['slug' => $slug])->save();

        return $slug;
    }

    public function activeQrCode(): ?QrCode
    {
        return $this->qrCodes()->where('state', 'active')->where('placement', 'counter')->latest('id')->first()
            ?? $this->qrCodes()->where('state', 'active')->whereNull('placement')->latest('id')->first()
            ?? $this->qrCodes()->where('state', 'active')->latest('id')->first();
    }
}
