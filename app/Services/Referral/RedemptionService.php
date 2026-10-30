<?php

namespace App\Services\Referral;

use App\Contracts\PhoneLookupGateway;
use App\Jobs\SendFirstVisitAlert;
use App\Jobs\SendRewardReadySms;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\BusinessAlert;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\User;
use App\Services\Attribution\AttributionService;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\DB;

/**
 * The redemption write paths (spec Section 6.1). Every transition
 * runs in a transaction under a row lock with the state re-read
 * inside the lock, so a double submit, a replayed or double-
 * scanned link, or two staff phones acting together produces
 * exactly one transition (RDM-15, CONC-02, CONC-07). Whoever holds
 * the phone performs the redemption; an authenticated owner or
 * staff session from the SAME business attributes it, and never
 * gates it (Step 10: no PIN, no account, no session required).
 */
class RedemptionService
{
    public function __construct(
        private RiskScoring $risk,
        private AttributionService $attribution,
        private PhoneLookupGateway $lookup,
    ) {}

    /**
     * Attribute a redemption actor: the authenticated user when
     * their session belongs to this business, else the counter.
     *
     * @return array{actor: string, user_id: ?int}
     */
    public function actorFor(?User $user, Business $business): array
    {
        if ($user !== null && (int) $user->business_id === (int) $business->id) {
            return ['actor' => 'owner', 'user_id' => $user->id];
        }

        return ['actor' => 'counter', 'user_id' => null];
    }

    /**
     * The creation-scored rules that convert to a hold at
     * redemption (Option B, V-B): an explicit allow-list, so a
     * future scoring rule is added here deliberately and never
     * inherited by accident. Today the only creation-scored
     * holding rule is device_signal_reuse (50 points, at the
     * RiskScoring::holdsAt threshold).
     *
     * @var list<string>
     */
    private const CONVERTED_CLAIM_RULES = ['device_signal_reuse'];

    /**
     * [TWO-KEY HUNK — redemption gap predicate] The 25-minute
     * scan-to-redemption gap (owner rulings 2026-10-27 and
     * 2026-10-29): the unlock moment for a referral, or null
     * when the redemption is NOT inside the window. The anchor
     * is the referral's OWN referrer membership created_at —
     * never a presented link's referrer, never the referral's
     * created_at, never the friend's membership. A null anchor
     * never blocks. At exactly anchor + gap the attempt passes.
     */
    public function gapUnlockAt(Referral $referral): ?\Illuminate\Support\Carbon
    {
        $anchor = $referral->referrerMembership?->created_at;

        if ($anchor === null) {
            return null;
        }

        $unlockAt = $anchor->copy()->addMinutes((int) config('referrals.claim_min_gap_minutes', 25));

        return now()->lessThan($unlockAt) ? $unlockAt : null;
    }

    /**
     * [TWO-KEY HUNK — blocked-attempt audit] Record one
     * 'redemption_blocked_gap' audit event for a refused
     * attempt (the RC-5 measurement replacement). This event
     * feeds NO TokenResolutionGuard budget and renders on NO
     * owner timeline by default.
     */
    public function recordGapBlock(Referral $referral, ?User $actorUser, ?string $ipAddress, \Illuminate\Support\Carbon $unlockAt): void
    {
        $business = $referral->business;
        $actor = $this->actorFor($actorUser, $business);

        ReferralEvent::record('redemption_blocked_gap', $actor['actor'], $business, $referral, null, $referral->referrerMembership, [
            'unlock_at' => $unlockAt->copy()->setTimezone(new \DateTimeZone('UTC'))->toIso8601String(),
            'referrer_membership_id' => $referral->referrer_membership_id,
        ], $ipAddress);
    }

    /**
     * Redeem a pending referral (Step 10). Outcomes:
     * redeemed | already | expired | voided | too_early |
     * missing_reward_text (Train F2 backstop).
     * (Option B: there is no 'held' outcome for referrals — a
     * flagged visit completes and its reward/billing are held.)
     *
     * @return array{outcome: string, referral: Referral, reward: ?Reward, unlock_at?: \Illuminate\Support\Carbon}
     */
    public function redeemReferral(Referral $referral, ?User $actorUser, ?string $ipAddress): array
    {
        $result = DB::transaction(function () use ($referral, $actorUser, $ipAddress) {
            $referral = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();
            $business = $referral->business;
            $actor = $this->actorFor($actorUser, $business);

            if ($referral->state === Referral::STATE_REDEEMED) {
                return ['outcome' => 'already', 'referral' => $referral, 'reward' => $referral->reward];
            }

            if ($referral->state === Referral::STATE_VOIDED) {
                return ['outcome' => 'voided', 'referral' => $referral, 'reward' => null];
            }

            if ($referral->applyExpiry()) {
                return ['outcome' => 'expired', 'referral' => $referral, 'reward' => null];
            }

            // G6 demo Phase 1 (Amendment 63, design pass Section
            // 6.2): a demo claim completes HERE, through this
            // service, and nowhere else. The demo branch requires
            // BOTH stamps (the claim's source and the business's
            // flag); either alone is a data error this branch
            // refuses to bill for. The visit completes, the
            // settlement row reads coverage='demo' at $0, and
            // nothing else in the money machinery moves: no
            // referrer exists, so no reward mints, no velocity
            // counts, no free slot is consumed, no cap room
            // moves, and no first-visit alert or attribution
            // fires for a fictional shop.
            if ($referral->source === 'demo' || $business->isDemo()) {
                if (! ($referral->source === 'demo' && $business->isDemo())) {
                    return ['outcome' => 'voided', 'referral' => $referral, 'reward' => null];
                }

                $referral->forceFill([
                    'state' => Referral::STATE_REDEEMED,
                    'redeemed_at' => now(),
                    'redeemed_by_user_id' => null,
                    'attribution_state' => 'unattributed',
                ])->save();

                ReferralEvent::record('redemption', $actor['actor'], $business, $referral, null, null, [
                    'redeemed_by' => $actor['actor'],
                    'channel' => 'demo',
                ], $ipAddress);

                BillingEvent::create([
                    'business_id' => $business->id,
                    'referral_id' => $referral->id,
                    'amount_cents' => 0,
                    'coverage' => 'demo',
                ]);

                return ['outcome' => 'redeemed', 'referral' => $referral, 'reward' => null];
            }

            // Merge order (demo merge, 2026-10-30): the demo
            // branch above runs FIRST — it fires only for a
            // demo-stamped claim on a demo business (or refuses
            // a stamp mismatch as voided) and by construction
            // mints nothing and bills $0, so neither money guard
            // below applies to it; the demo counter step also
            // runs right away by design, never gap-blocked. For
            // every real referral the branch falls through
            // untouched and the pair guard then the gap guard
            // run in their ruled order.
            // BREAKER D-1 redemption gate (2026-10-29): the pair
            // guard. A visit counts once per (business, friend):
            // if another referral for this pair already redeemed
            // (a duplicate pair minted before the dedupe fix, or
            // raced past the claim gates), this presentation mints
            // no second reward and bills no second fee. The
            // duplicate itself is left pending and expires on its
            // own clock; the counted visit is the first one.
            // The counted visit is the pair's SURVIVOR: a
            // redeemed row stamped pair_superseded_at by
            // migration 000039's dedupe resolution is a duplicate
            // the money review still owes a decision on, never
            // the governing visit. Fall back to any redeemed row
            // only if no survivor exists (defensive: resolution
            // always leaves exactly one survivor per pair).
            $alreadyCounted = Referral::query()
                ->where('business_id', $referral->business_id)
                ->where('referred_customer_id', $referral->referred_customer_id)
                ->where('state', Referral::STATE_REDEEMED)
                ->where('id', '!=', $referral->id)
                ->whereNull('pair_superseded_at')
                ->first()
                ?? Referral::query()
                    ->where('business_id', $referral->business_id)
                    ->where('referred_customer_id', $referral->referred_customer_id)
                    ->where('state', Referral::STATE_REDEEMED)
                    ->where('id', '!=', $referral->id)
                    ->first();

            if ($alreadyCounted !== null) {
                return ['outcome' => 'already', 'referral' => $referral, 'reward' => $alreadyCounted->reward];
            }

            // Merge order (gap merge, 2026-10-30): the pair
            // guard above runs FIRST (a counted pair's outcome
            // is terminal and truthful at any time), then the
            // gap guard below — still ahead of the F2 backstop
            // and all Option B computation, as its two-key
            // review required.
            // [TWO-KEY HUNK — redemption block + token state]
            // The 25-minute gap, enforced HERE (owner ruling
            // 2026-10-29, moving the 2026-10-27 claim gate):
            // inside the window the redemption is refused and
            // NOTHING else runs — no state flip, no velocity or
            // anomaly computation, no BillingEvent, no Reward,
            // no flag_raised. The referral stays
            // pending_redemption with its token, hash, and
            // expiry untouched, so the same code completes on a
            // retry after the time. This fires BEFORE the F2
            // backstop and BEFORE any Option B conversion: a
            // hold can only be computed on a completed visit.
            $gapUnlockAt = $this->gapUnlockAt($referral);

            if ($gapUnlockAt !== null) {
                ReferralEvent::record('redemption_blocked_gap', $actor['actor'], $business, $referral, null, $referral->referrerMembership, [
                    'unlock_at' => $gapUnlockAt->copy()->setTimezone(new \DateTimeZone('UTC'))->toIso8601String(),
                    'referrer_membership_id' => $referral->referrer_membership_id,
                ], $ipAddress);

                return ['outcome' => 'too_early', 'referral' => $referral, 'reward' => null, 'unlock_at' => $gapUnlockAt];
            }

            // Option B (owner ruling 2026-10-28): there is NO
            // referral-hold gate here. Claim creation no longer
            // sets referrals.hold for the converted rules, and
            // any legacy held row was converted by the release's
            // Gate 1 normalization — a pending referral presented
            // at the counter completes, and the flags it carries
            // hold only the minted reward and its billing
            // coverage (below).

            // Train F2 backstop (finding F-2): the reward text the
            // mint below would snapshot, normalized — the trimmed
            // referrer text, else the trimmed friend text. The
            // activation gate (Business::flowIsActive) keeps a
            // textless business from ever producing a claim, but a
            // referral already in flight when the texts were never
            // set (or were un-set by an Undo restore) reaches here
            // with nothing to snapshot. Minting then would either
            // 500 on the NOT NULL snapshot column (the F-2 crash,
            // rolling back the whole visit) or mint a valueless
            // reward and bill for it. Instead the press is refused
            // by design: one audit event, the referral stays
            // pending and completable once the owner sets a
            // reward, and no billing event or reward is written.
            // This is not a hold: no hold flag, no review queue,
            // no billing consequence.
            // OR-1 §2.3/E3 re-key: the snapshot source is the
            // resolver — SPLIT mints the referrer text, UNIFIED
            // mints the one reward — stated in the model's own
            // terms, so the backstop's premise ("no reward text
            // to snapshot") is exactly "the resolver is empty".
            $snapshot = trim((string) ($business->effectiveReferrerRewardText() ?? ''));

            if ($snapshot === '') {
                ReferralEvent::record('redemption_blocked_missing_reward_text', $actor['actor'], $business, $referral, null, $referral->referrerMembership, [
                    'reason' => 'missing_reward_text',
                ], $ipAddress);

                return ['outcome' => 'missing_reward_text', 'referral' => $referral, 'reward' => null];
            }

            // The redemption-time velocity recheck (CONC-01): under
            // the referrer membership's row lock, so parallel
            // redemptions of one referrer's links can never push
            // the referrer past the rewarded cap. Option B: the
            // breach is COLLECTED as a converted rule, not
            // returned — the visit completes below, and the rule
            // holds the minted reward + billing coverage instead.
            $referrerMembership = $referral->referrerMembership()->lockForUpdate()->firstOrFail();

            $convertedRules = [];

            $velocityRule = $this->redemptionVelocityBreach($business, $referrerMembership->id);

            if ($velocityRule !== null) {
                $convertedRules[] = $velocityRule;
            }

            // The claim-flag leg: the creation-time risk score,
            // persisted as data, converts here iff it reaches the
            // hold threshold AND carries an allow-listed rule.
            if ($this->risk->holdsAt((int) ($referral->risk_score ?? 0))) {
                foreach (self::CONVERTED_CLAIM_RULES as $claimRule) {
                    if (in_array($claimRule, $referral->risk_rules ?? [], true)) {
                        $convertedRules[] = $claimRule;
                    }
                }
            }

            $referral->forceFill([
                'state' => Referral::STATE_REDEEMED,
                'redeemed_at' => now(),
                'redeemed_by_user_id' => $actor['user_id'],
                'attribution_state' => $actor['user_id'] !== null ? 'attributed' : 'unattributed',
            ])->save();

            ReferralEvent::record('redemption', $actor['actor'], $business, $referral, null, $referral->referrerMembership, [
                'redeemed_by' => $actor['actor'],
            ], $ipAddress);

            // Step 10's only money-side write: exactly one billing
            // event row per referral (the unique index enforces
            // it). Coverage and the charge are settled by the
            // Phase 4 batch; the free-visit balance is untouched
            // here (Section 9.5).
            $plan = $business->currentPlan ?? Plan::launch();

            if ($plan === null) {
                throw new \RuntimeException('Business '.$business->id.' has no plan configured.');
            }

            // The anomaly rules run before the event is written
            // (money fix train, audit 3.4): a visit whose reward
            // mints under an anomaly hold is marked held on its
            // billing event, so the settlement walk excludes it
            // until review releases the reward. A held visit
            // bills only when released. Option B: the converted
            // claim-time rules (velocity + device reuse, computed
            // above) UNION with the anomaly rules — one held
            // reward, one held billing event, one flag_raised.
            $anomalies = $this->risk->redemptionAnomalies($business, $referrerMembership);
            $heldRules = array_values(array_unique([...$anomalies, ...$convertedRules]));

            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => (int) $plan->per_referral_cents,
                'coverage' => $heldRules !== [] ? 'held' : 'billable',
            ]);

            // Mint the referrer's reward in the same transaction:
            // pending vesting, holding until the vesting date,
            // with the reward text snapshotted (the referrer's own
            // text, defaulting to the friend's per OD-2).
            $claimToken = OpaqueToken::generate();
            // OD-3: the vesting hold is the business's own
            // reward_vesting_days setting (GAP 2; default 14,
            // permitted range 7 to 14). A hand-edited or legacy
            // value outside the range falls back to the config
            // default rather than minting an out-of-range reward
            // (BILL-43). The stamp happens here, at mint, so a
            // later settings change never moves this reward.
            $configuredVesting = (int) $business->reward_vesting_days;
            $vestingDays = $configuredVesting >= (int) config('referrals.vesting_days_min')
                && $configuredVesting <= (int) config('referrals.vesting_days_max')
                ? $configuredVesting
                : (int) config('referrals.vesting_days_default');

            // M-4 (design pass Section 1.3): the use-by date is
            // computed from the SAME availability instant and
            // stamped at mint beside it, so the date is stable
            // for the reward's whole life and printable from day
            // one. Calendar months, so the printed date is the
            // same calendar date one year on.
            $availableAt = now()->addDays($vestingDays);

            $reward = Reward::create([
                'business_id' => $business->id,
                'membership_id' => $referrerMembership->id,
                'referral_id' => $referral->id,
                'reward_text_snapshot' => $snapshot,
                'claim_token_hash' => OpaqueToken::hash($claimToken),
                'claim_token_encrypted' => $claimToken,
                'available_at' => $availableAt,
                'expires_at' => $availableAt->copy()->addMonths((int) config('referrals.reward_expiry_months', 12)),
                'state' => Reward::STATE_PENDING_VESTING,
                'hold' => $heldRules !== [],
                'hold_rules' => $heldRules === [] ? null : $heldRules,
                'held_at' => $heldRules === [] ? null : now(),
                'notification_state' => 'queued',
            ]);

            ReferralEvent::record('reward_minted', 'system', $business, $referral, $reward, $referrerMembership, [], $ipAddress);

            if ($heldRules !== []) {
                ReferralEvent::record('flag_raised', 'system', $business, $referral, $reward, $referrerMembership, [
                    'rules' => $heldRules,
                ], $ipAddress);
            }

            // The one-hour minimum-age soft signal (money fix
            // train, audit 2.2): a redemption inside the
            // referral's first hour is recorded on the referral
            // (rule plus its small score) and as a risk_signal
            // event for the review queue. Signal only, never a
            // gate: it holds nothing, blocks nothing, and the
            // visit bills exactly as it would without it.
            $ageSignal = $this->risk->minimumAgeSignal($referral);

            if ($ageSignal !== null) {
                $rules = $referral->risk_rules ?? [];

                if (! in_array($ageSignal, $rules, true)) {
                    $rules[] = $ageSignal;
                }

                $referral->forceFill([
                    'risk_rules' => $rules,
                    'risk_score' => (int) ($referral->risk_score ?? 0)
                        + (int) config('referrals.risk_points.referral_age_under_one_hour', 0),
                ])->save();

                ReferralEvent::record('risk_signal', 'system', $business, $referral, $reward, $referrerMembership, [
                    'rules' => [$ageSignal],
                ], $ipAddress);
            }

            // E7 first_verified_visit and the Step 11b first-visit
            // alert claim: the first redemption this business ever
            // records.
            $isFirst = ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', 'redemption')
                ->count() === 1;

            $alertClaimed = false;

            if ($isFirst) {
                $this->attribution->record('E7', $business);
                $alert = BusinessAlert::firstOrCreate(
                    ['business_id' => $business->id, 'kind' => 'first_visit'],
                    ['notification_state' => 'queued']
                );
                $alertClaimed = $alert->wasRecentlyCreated;
            }

            return ['outcome' => 'redeemed', 'referral' => $referral, 'reward' => $reward, 'alert' => $alertClaimed, 'business_id' => $business->id];
        });

        if (($result['outcome'] ?? null) === 'redeemed' && ! empty($result['alert'])) {
            SendFirstVisitAlert::dispatch((int) $result['business_id']);
        }

        // Step 11: the reward-ready SMS waits for vesting. The
        // reward row carries notification_state queued from mint;
        // the rewards:notify-due sweep dispatches the send when the
        // reward becomes claimable (and the review release path
        // dispatches it directly when a hold clears after vesting).

        return $result;
    }

    /**
     * Claim a vested reward at the counter (Steps 2 and 3 of the
     * claim side). Outcomes:
     * claimed | already | not_yet | held | denied.
     *
     * @return array{outcome: string, reward: Reward}
     */
    public function claimReward(Reward $reward, ?User $actorUser, ?string $ipAddress): array
    {
        return DB::transaction(function () use ($reward, $actorUser, $ipAddress) {
            $reward = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();
            $business = $reward->business;
            $actor = $this->actorFor($actorUser, $business);

            if ($reward->state === Reward::STATE_CLAIMED) {
                return ['outcome' => 'already', 'reward' => $reward];
            }

            if ($reward->state === Reward::STATE_DENIED) {
                return ['outcome' => 'denied', 'reward' => $reward];
            }

            if ($reward->hold) {
                return ['outcome' => 'held', 'reward' => $reward];
            }

            // The closure wind-down end is enforced state-side,
            // not only on the pages (spec Section 5.5): a claim
            // attempted after day 30 expires the reward instead.
            // M-4: the reward's own 12-month date is enforced the
            // same way, beside it (pass Section 2.7); whichever
            // end came first, the outcome below is the existing
            // 'expired'. Vesting runs FIRST, in the claimPage /
            // staff-page order (two-key fix F1): time expiry
            // guards on state available, so running it before
            // vesting could never see a both-due pending_vesting
            // row (vesting elapsed AND use-by date past) and the
            // claim consumed it instead of expiring it.
            $reward->applyVesting();
            $reward->applyClosureExpiry();
            $reward->applyTimeExpiry();

            if ($reward->state === Reward::STATE_EXPIRED) {
                return ['outcome' => 'expired', 'reward' => $reward];
            }

            if ($reward->state === Reward::STATE_PENDING_VESTING) {
                return ['outcome' => 'not_yet', 'reward' => $reward];
            }

            // EC-16 / REF-54: a claim inside the number-link
            // cooling window on a previously dormant membership
            // does not complete during the window; it holds for
            // owner review. The Twilio Lookup SIM-swap check runs
            // as a step-up signal on that claim: a recent swap is
            // recorded as an additional rule on the hold, never as
            // a hard block of its own.
            $membership = $reward->membership;
            $customer = $membership->customer;
            $coolingDays = (int) config('referrals.number_link_cooling_days');

            if ($customer->number_linked_at !== null
                && $customer->number_linked_at->greaterThanOrEqualTo(now()->subDays($coolingDays))
                && $membership->created_at !== null
                && $membership->created_at->lessThanOrEqualTo(now()->subDays($coolingDays))
                && ! $this->membershipHasClaimedBefore($membership->id, $reward->id)) {
                $lookup = $this->lookup->lookup($customer->phone_e164);

                $rules = ['number_link_cooling'];

                if (($lookup['sim_changed_recently'] ?? false) === true) {
                    $rules[] = 'sim_swap_step_up';
                }

                $reward->forceFill([
                    'hold' => true,
                    'hold_rules' => $rules,
                    'held_at' => now(),
                    // A NEW hold episode (BREAKER D-2): any
                    // earlier review resolution no longer governs
                    // this hold, so its stamp clears.
                    'hold_resolved_at' => null,
                    'hold_resolved_by' => null,
                ])->save();

                ReferralEvent::record('flag_raised', 'system', $business, $reward->referral, $reward, $membership, [
                    'rules' => $rules,
                ], $ipAddress);

                return ['outcome' => 'held', 'reward' => $reward];
            }

            $reward->forceFill([
                'state' => Reward::STATE_CLAIMED,
                'claimed_at' => now(),
                'claimed_by_user_id' => $actor['user_id'],
            ])->save();

            ReferralEvent::record('reward_claimed', $actor['actor'], $business, $reward->referral, $reward, $membership, [
                'claimed_by' => $actor['actor'],
            ], $ipAddress);

            return ['outcome' => 'claimed', 'reward' => $reward];
        });
    }

    /**
     * Owner review actions (Section 5.6): release clears the hold
     * and the object resumes its normal lifecycle; deny is terminal.
     */
    /**
     * Whether a row's current hold is an UNRESOLVED review
     * (BREAKER D-2, 2026-10-29): it is held now, and the hold
     * episode began after any earlier resolution stamp. Release
     * and deny both stamp hold_resolved_at when they resolve a
     * hold, and every later hold episode (the claim-time cooling
     * hold, a refund clawback) re-stamps held_at and clears the
     * resolution, so a re-held row is unresolved again while a
     * resolved one can never be re-decided.
     */
    private function holdIsUnresolved(Referral|Reward $item): bool
    {
        if (! $item->hold) {
            return false;
        }

        if ($item->hold_resolved_at === null) {
            return true;
        }

        return $item->held_at !== null && $item->held_at->greaterThan($item->hold_resolved_at);
    }

    public function releaseReferral(Referral $referral): void
    {
        DB::transaction(function () use ($referral) {
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            // BREAKER D-2 close (two-key Finding 3 hardening):
            // a terminal state is a no-op for release REGARDLESS
            // of the resolution stamp, so no present or future
            // deny writer can reopen the door by forgetting to
            // stamp. Voided is the referral's denied state.
            if ($locked->state === Referral::STATE_VOIDED) {
                return;
            }

            // BREAKER D-2: a resolved review is terminal. A
            // release replay (or a release posted after a deny)
            // on an already-resolved, unheld referral is an
            // idempotent no-op: no second resolution event, no
            // window top-up, no state movement.
            if (! $locked->hold && $locked->hold_resolved_at !== null) {
                return;
            }

            $fill = [
                'hold' => false,
                'hold_rules' => null,
                'held_at' => null,
            ];

            if ($locked->hold) {
                $fill['hold_resolved_at'] = now();
            }

            // SA-03 (Amendment 35): a held referral never
            // expired while it waited (Referral::applyExpiry
            // skips held rows), so its stored window may be
            // spent or nearly spent at release. Top the window
            // up to the fresh minimum: the lesser of the
            // platform minimum (config) and the business's own
            // redemption window. A referral whose remaining
            // window already meets the minimum keeps its date.
            // Presentation and lifecycle only: no billing event
            // exists before redemption, so no money state moves.
            //
            // Abuse pass (2026-10-28): the top-up is gated on
            // the referral actually having been held at lock
            // time. Without that gate, the release endpoint
            // (which does not itself require a hold) would be
            // a window-extension button: re-posting Release on
            // any pending referral would push its date to
            // now + the minimum, indefinitely. A release of an
            // unheld referral keeps its original behavior
            // exactly: hold fields cleared (already clear), a
            // review_release event, and no date movement.
            if ($locked->hold && $locked->state === Referral::STATE_PENDING && $locked->expires_at !== null) {
                $minimumDays = min(
                    (int) config('referrals.held_release_min_window_days', 7),
                    (int) $locked->business->redemption_window_days,
                );
                $floor = now()->addDays(max(1, $minimumDays));

                if ($locked->expires_at->lessThan($floor)) {
                    $fill['expires_at'] = $floor;
                }
            }

            $locked->forceFill($fill)->save();

            ReferralEvent::record('review_release', 'owner', $locked->business, $locked);
        });
    }

    /**
     * Deny is a pre-completion remedy only (security verifier M-4):
     * it runs inside the same lock discipline as redemption
     * (Section 7.4) and refuses once the referral has left the
     * pending state, so a redeemed visit can never be voided out
     * from under its billing event and minted reward.
     *
     * BREAKER D-2 (2026-10-29): deny is additionally the
     * resolution of a REVIEW, not a general owner power over any
     * pending row: it is legal ONLY from a held, unresolved hold
     * (see holdIsUnresolved). A never-held referral, and a
     * referral whose hold was already resolved (released or
     * denied), refuses. Returns whether the deny actually
     * happened.
     */
    public function denyReferral(Referral $referral): bool
    {
        return DB::transaction(function () use ($referral) {
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== Referral::STATE_PENDING || ! $this->holdIsUnresolved($locked)) {
                return false;
            }

            $locked->forceFill([
                'state' => Referral::STATE_VOIDED,
                'hold' => false,
                'hold_resolved_at' => now(),
            ])->save();

            ReferralEvent::record('review_deny', 'owner', $locked->business, $locked);

            return true;
        });
    }

    public function releaseReward(Reward $reward): void
    {
        DB::transaction(function () use ($reward) {
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();

            // BREAKER D-2 close (two-key Finding 3 hardening):
            // a terminal denied reward is a no-op for release
            // REGARDLESS of the resolution stamp, so no present
            // or future deny writer (the clawback cancel was the
            // one that did not stamp) can reopen the door.
            if ($locked->state === Reward::STATE_DENIED) {
                return;
            }

            // BREAKER D-2: a resolved review is terminal. A
            // release replay (or a release posted after a deny)
            // on an already-resolved, unheld reward is an
            // idempotent no-op: it cannot resurrect a denied
            // reward, re-run the billing flip, or re-dispatch
            // the reward-ready text.
            if (! $locked->hold && $locked->hold_resolved_at !== null) {
                return;
            }

            // M-4 (pass Section 2.5): whether this release is
            // resolving a REAL hold is read before the fill below
            // clears it; the top-up gate hangs on this value.
            $wasHeld = (bool) $locked->hold;

            $locked->forceFill([
                'hold' => false,
                'hold_rules' => null,
                'held_at' => null,
                'hold_resolved_at' => $locked->hold ? now() : $locked->hold_resolved_at,
            ])->save();

            // A held visit bills only when released (money fix
            // train): the billing event minted as held returns
            // to the settlement walk's normal input. Events
            // carrying any other coverage are untouched, so a
            // release for a non-billing hold (the number-link
            // cooling window) changes no money state.
            BillingEvent::query()
                ->where('referral_id', $locked->referral_id)
                ->where('coverage', 'held')
                ->update(['coverage' => 'billable']);

            $locked->applyVesting();

            // M-4 release top-up (the SA-03 twin, pass Section
            // 2.5): the 12-month clock paused while this reward
            // was held (applyTimeExpiry refuses held rows), so
            // its stored date may be spent or nearly spent at
            // release. Top it up to at least now + the platform
            // release minimum, so a released customer always has
            // a usable window. Gated on the reward actually
            // having been held at lock time (the releaseReferral
            // abuse discipline): without the gate, re-posting
            // Release on any reward would be a date-extension
            // button. A reward with more than the minimum left
            // keeps its date untouched, and a legacy row with no
            // stamped date gains none here.
            if ($wasHeld && $locked->expires_at !== null) {
                $floor = now()->addDays(max(1, (int) config('referrals.reward_expiry_release_min_days', 30)));

                if ($locked->expires_at->lessThan($floor)) {
                    $locked->forceFill(['expires_at' => $floor])->save();
                }
            }

            ReferralEvent::record('review_release', 'owner', $locked->business, $locked->referral, $locked);

            if ($locked->state === Reward::STATE_AVAILABLE && $locked->notification_state === 'queued') {
                SendRewardReadySms::dispatch($locked->id);
            }
        });
    }

    /**
     * Deny is a pre-completion remedy only (security verifier M-4):
     * a claimed reward can no longer be denied after the fact; the
     * state guard runs under the row lock.
     *
     * Money side (money fix train, audit 1.3, coordinator
     * ruling): a fraud-denied visit is not billable. When the
     * visit's billing event is still unclaimed, the deny marks
     * it fraud_denied in the same transaction, so the settlement
     * walk excludes it permanently; a visit that had consumed
     * free credit hands the slot back. A visit already claimed
     * by a charge is ledger history and is left alone: un-billing
     * a charged visit is the refund path's job.
     *
     * BREAKER D-2 (2026-10-29): like denyReferral, deny here is
     * the resolution of a REVIEW: legal ONLY from a held,
     * unresolved hold in a pre-claim state. A never-held reward
     * (including a ready-to-claim available one), a reward whose
     * hold was already released (release is terminal), and a
     * reward already denied all refuse, and the billing exclusion
     * below therefore runs exactly once, for a real deny.
     */
    public function denyReward(Reward $reward): bool
    {
        return DB::transaction(function () use ($reward) {
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->state, [Reward::STATE_PENDING_VESTING, Reward::STATE_AVAILABLE], true)
                || ! $this->holdIsUnresolved($locked)) {
                return false;
            }

            $locked->forceFill([
                'state' => Reward::STATE_DENIED,
                'hold' => false,
                'hold_resolved_at' => now(),
            ])->save();

            $this->excludeVisitFromBilling($locked);

            ReferralEvent::record('review_deny', 'owner', $locked->business, $locked->referral, $locked);

            return true;
        });
    }

    /**
     * Mark a denied reward's visit as no longer billable, with
     * the free-slot restore. Runs inside denyReward's
     * transaction; a no-op when there is no billing event, when
     * the event already carries a terminal or held-out coverage,
     * or when a charge record has claimed the visit.
     */
    private function excludeVisitFromBilling(Reward $reward): void
    {
        $event = BillingEvent::query()->where('referral_id', $reward->referral_id)->first();

        if ($event === null
            || $event->billing_record_id !== null
            || ! in_array($event->coverage, ['billable', 'free_credit', 'held'], true)) {
            return;
        }

        $wasFreeCredit = $event->coverage === 'free_credit';

        $event->forceFill(['coverage' => 'fraud_denied'])->save();

        if ($wasFreeCredit) {
            // Hand the slot back, guarded so the balance can
            // never exceed the signup allowance it came from.
            Business::query()
                ->whereKey($reward->business_id)
                ->where('free_visits_remaining', '<', (int) config('billing.free_visits_at_signup', 5))
                ->increment('free_visits_remaining');
        }
    }

    /**
     * Redeemed counts against the caps in the business-local day
     * and month (the redemption-time leg of the velocity rule).
     */
    private function redemptionVelocityBreach(Business $business, int $referrerMembershipId): ?string
    {
        $timezone = new \DateTimeZone($business->timezone ?: 'America/New_York');

        $redeemedToday = Referral::query()
            ->where('business_id', $business->id)
            ->where('referrer_membership_id', $referrerMembershipId)
            ->where('state', Referral::STATE_REDEEMED)
            ->where('redeemed_at', '>=', now()->setTimezone($timezone)->startOfDay()->setTimezone(new \DateTimeZone('UTC')))
            ->count();

        if ($redeemedToday >= (int) $business->daily_referral_cap) {
            return 'velocity_daily';
        }

        $redeemedThisMonth = Referral::query()
            ->where('business_id', $business->id)
            ->where('referrer_membership_id', $referrerMembershipId)
            ->where('state', Referral::STATE_REDEEMED)
            ->where('redeemed_at', '>=', now()->setTimezone($timezone)->startOfMonth()->setTimezone(new \DateTimeZone('UTC')))
            ->count();

        if ($redeemedThisMonth >= (int) $business->monthly_referral_cap) {
            return 'velocity_monthly';
        }

        return null;
    }

    private function membershipHasClaimedBefore(int $membershipId, int $exceptRewardId): bool
    {
        return Reward::query()
            ->where('membership_id', $membershipId)
            ->where('id', '!=', $exceptRewardId)
            ->where('state', Reward::STATE_CLAIMED)
            ->exists();
    }
}
