<?php

namespace App\Services\Referral;

use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;

/**
 * Risk scoring (spec Section 3.1). Signals accumulate into a score
 * stored on the referral with machine-readable rule IDs, so every
 * hold's reason is answerable in the owner's review queue in human
 * sentences. A shared IP address alone never holds: it contributes
 * nothing without a second, independent signal.
 */
class RiskScoring
{
    /**
     * Score a freshly created referral from its creation context.
     *
     * @param  array{device_signal_hash: ?string, same_ip_as_referrer: bool}  $context
     * @return array{score: int, rules: list<string>}
     */
    public function scoreNewReferral(Referral $referral, array $context): array
    {
        $points = (array) config('referrals.risk_points');
        $score = 0;
        $rules = [];

        // A device signal already carried by a different customer at
        // this business is the farming signature (one handset
        // manufacturing friends). It needs the recorded signal from
        // an earlier verification at this business to compare with.
        if (($context['device_signal_hash'] ?? null) !== null
            && $this->deviceSignalSeenForAnotherCustomer($referral, $context['device_signal_hash'])) {
            $score += (int) ($points['device_signal_reuse'] ?? 0);
            $rules[] = 'device_signal_reuse';
        }

        return ['score' => $score, 'rules' => $rules];
    }

    /**
     * The counter anomaly rules (spec Section 3.1), evaluated at a
     * redemption against the business's trailing windows. Returns
     * the rule IDs that fired; a hit can hold the minted reward,
     * never the redemption itself.
     *
     * @return list<string>
     */
    public function redemptionAnomalies(Business $business, Membership $referrerMembership): array
    {
        $config = (array) config('referrals.anomaly');
        $rules = [];

        // (a) Burst: redemptions in the last 60 minutes against the
        // trailing 28-day average for the same local hour.
        $lastHour = Referral::query()
            ->where('business_id', $business->id)
            ->where('state', Referral::STATE_REDEEMED)
            ->where('redeemed_at', '>=', now()->subHour())
            ->count();

        if ($lastHour >= (int) $config['burst_minimum']) {
            // The baseline is the trailing 28-day average for
            // the SAME local hour (Section 3.1 rule (a)): count
            // only redemptions whose local hour matches the
            // current one; that hour occurs once per day in the
            // window, so the divisor is 28.
            $localHour = (int) now($business->timezone)->format('G');
            $sameHour = Referral::query()
                ->where('business_id', $business->id)
                ->where('state', Referral::STATE_REDEEMED)
                ->where('redeemed_at', '>=', now()->subDays(28))
                ->pluck('redeemed_at')
                ->filter(fn ($at) => (int) \Illuminate\Support\Carbon::parse($at)->setTimezone($business->timezone)->format('G') === $localHour)
                ->count();
            $baseline = $sameHour / 28;

            if ($baseline > 0 && $lastHour > (int) $config['burst_multiple'] * $baseline) {
                $rules[] = 'redemption_burst';
            }
        }

        // (b) Out-of-hours share: no business configures open hours
        // in v2 yet, so every business is exempt by the rule's own
        // terms (spec Section 3.1) until hours exist.

        // (c) Near-total conversion over a rolling 7 days.
        $enrollments = $business->memberships()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $redemptions7d = Referral::query()
            ->where('business_id', $business->id)
            ->where('state', Referral::STATE_REDEEMED)
            ->where('redeemed_at', '>=', now()->subDays(7))
            ->count();

        if ($enrollments >= (int) $config['conversion_minimum_enrollments']
            && $redemptions7d / max(1, $enrollments) > (float) $config['conversion_ratio']) {
            $rules[] = 'near_total_conversion';
        }

        // (d) Unattributed share over a rolling 7 days (actor
        // `counter` in the redemption events), plus the week-over-
        // week jump.
        [$unattributed, $total] = $this->unattributedShare($business, now()->subDays(7), now());
        [$prevUnattributed, $prevTotal] = $this->unattributedShare($business, now()->subDays(14), now()->subDays(7));

        if ($total >= (int) $config['unattributed_minimum']
            && $unattributed / $total > (float) $config['unattributed_ratio']) {
            $rules[] = 'unattributed_share';
        } elseif ($total >= (int) $config['unattributed_minimum'] && $prevTotal >= (int) $config['unattributed_minimum']) {
            $jump = ($unattributed / $total - $prevUnattributed / $prevTotal) * 100;

            if ($jump > (int) $config['unattributed_week_jump_points']) {
                $rules[] = 'unattributed_share';
            }
        }

        // Referrer concentration: one membership producing an
        // outsized share of the business's redemptions (rolling 30
        // days, minimum sample 10) is scored and surfaced.
        $total30d = Referral::query()
            ->where('business_id', $business->id)
            ->where('state', Referral::STATE_REDEEMED)
            ->where('redeemed_at', '>=', now()->subDays(30))
            ->count();

        if ($total30d >= 10) {
            $mine = Referral::query()
                ->where('business_id', $business->id)
                ->where('referrer_membership_id', $referrerMembership->id)
                ->where('state', Referral::STATE_REDEEMED)
                ->where('redeemed_at', '>=', now()->subDays(30))
                ->count();

            if ($mine / $total30d > 0.25) {
                $rules[] = 'referrer_concentration';
            }
        }

        return $rules;
    }

    public function holdsAt(int $score): bool
    {
        return $score >= (int) config('referrals.risk_hold_threshold');
    }

    /**
     * The one-hour minimum-age soft signal (money fix train,
     * audit 2.2): the rule ID when the referral is being
     * redeemed inside its first hour, null otherwise. This is
     * a review and risk SIGNAL only, never a gate: callers
     * record it and move on; nothing holds, blocks, or
     * un-bills on the strength of this rule alone.
     */
    public function minimumAgeSignal(Referral $referral, ?\DateTimeInterface $at = null): ?string
    {
        if ($referral->created_at === null) {
            return null;
        }

        $windowMinutes = (int) config('referrals.minimum_age_signal_minutes', 60);
        $ageSeconds = ($at ?? now())->getTimestamp() - $referral->created_at->getTimestamp();

        return $ageSeconds >= 0 && $ageSeconds < $windowMinutes * 60
            ? 'referral_age_under_one_hour'
            : null;
    }

    private function deviceSignalSeenForAnotherCustomer(Referral $referral, string $deviceSignalHash): bool
    {
        return \App\Models\ReferralEvent::query()
            ->where('business_id', $referral->business_id)
            ->where('device_signal_hash', $deviceSignalHash)
            ->where('event_type', 'verification_passed')
            ->whereHas('membership', function ($query) use ($referral) {
                $query->where('customer_id', '!=', $referral->referred_customer_id);
            })
            ->exists();
    }

    /**
     * @return array{0: int, 1: int} unattributed count, total count
     */
    private function unattributedShare(Business $business, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = \App\Models\ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'redemption')
            ->whereBetween('created_at', [$from, $to]);

        $total = (clone $events)->count();
        $unattributed = (clone $events)->where('actor', 'counter')->count();

        return [$unattributed, $total];
    }
}
