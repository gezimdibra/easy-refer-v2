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
     * Redeem a pending referral (Step 10). Outcomes:
     * redeemed | already | expired | held | voided.
     *
     * @return array{outcome: string, referral: Referral, reward: ?Reward}
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

            if ($referral->hold) {
                return ['outcome' => 'held', 'referral' => $referral, 'reward' => null];
            }

            // The redemption-time velocity recheck (CONC-01): under
            // the referrer membership's row lock, so parallel
            // redemptions of one referrer's links can never push
            // the referrer past the rewarded cap.
            $referrerMembership = $referral->referrerMembership()->lockForUpdate()->firstOrFail();
            $velocityRule = $this->redemptionVelocityBreach($business, $referrerMembership->id);

            if ($velocityRule !== null) {
                $referral->forceFill([
                    'hold' => true,
                    'hold_rules' => [$velocityRule],
                    'held_at' => now(),
                ])->save();

                ReferralEvent::record('flag_raised', 'system', $business, $referral, null, null, [
                    'rules' => [$velocityRule],
                ], $ipAddress);

                return ['outcome' => 'held', 'referral' => $referral, 'reward' => null];
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

            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => (int) $plan->per_referral_cents,
            ]);

            // Mint the referrer's reward in the same transaction:
            // pending vesting, holding until the vesting date,
            // with the reward text snapshotted (the referrer's own
            // text, defaulting to the friend's per OD-2).
            $anomalies = $this->risk->redemptionAnomalies($business, $referrerMembership);
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

            $reward = Reward::create([
                'business_id' => $business->id,
                'membership_id' => $referrerMembership->id,
                'referral_id' => $referral->id,
                'reward_text_snapshot' => $business->referrer_reward_text ?: $business->friend_reward_text,
                'claim_token_hash' => OpaqueToken::hash($claimToken),
                'claim_token_encrypted' => $claimToken,
                'available_at' => now()->addDays($vestingDays),
                'state' => Reward::STATE_PENDING_VESTING,
                'hold' => $anomalies !== [],
                'hold_rules' => $anomalies === [] ? null : $anomalies,
                'held_at' => $anomalies === [] ? null : now(),
                'notification_state' => 'queued',
            ]);

            ReferralEvent::record('reward_minted', 'system', $business, $referral, $reward, $referrerMembership, [], $ipAddress);

            if ($anomalies !== []) {
                ReferralEvent::record('flag_raised', 'system', $business, $referral, $reward, $referrerMembership, [
                    'rules' => $anomalies,
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
            $reward->applyClosureExpiry();

            if ($reward->state === Reward::STATE_EXPIRED) {
                return ['outcome' => 'expired', 'reward' => $reward];
            }

            $reward->applyVesting();

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
    public function releaseReferral(Referral $referral): void
    {
        DB::transaction(function () use ($referral) {
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'hold' => false,
                'hold_rules' => null,
                'held_at' => null,
            ])->save();

            ReferralEvent::record('review_release', 'owner', $locked->business, $locked);
        });
    }

    /**
     * Deny is a pre-completion remedy only (security verifier M-4):
     * it runs inside the same lock discipline as redemption
     * (Section 7.4) and refuses once the referral has left the
     * pending state, so a redeemed visit can never be voided out
     * from under its billing event and minted reward. Returns
     * whether the deny actually happened.
     */
    public function denyReferral(Referral $referral): bool
    {
        return DB::transaction(function () use ($referral) {
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== Referral::STATE_PENDING) {
                return false;
            }

            $locked->forceFill([
                'state' => Referral::STATE_VOIDED,
                'hold' => false,
            ])->save();

            ReferralEvent::record('review_deny', 'owner', $locked->business, $locked);

            return true;
        });
    }

    public function releaseReward(Reward $reward): void
    {
        DB::transaction(function () use ($reward) {
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'hold' => false,
                'hold_rules' => null,
                'held_at' => null,
            ])->save();

            $locked->applyVesting();

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
     */
    public function denyReward(Reward $reward): bool
    {
        return DB::transaction(function () use ($reward) {
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->state, [Reward::STATE_PENDING_VESTING, Reward::STATE_AVAILABLE], true)) {
                return false;
            }

            $locked->forceFill([
                'state' => Reward::STATE_DENIED,
                'hold' => false,
            ])->save();

            ReferralEvent::record('review_deny', 'owner', $locked->business, $locked->referral, $locked);

            return true;
        });
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
