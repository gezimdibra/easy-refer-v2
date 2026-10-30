<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\ReferralEvent;
use App\Models\Reward;
use Illuminate\Support\Facades\DB;

/**
 * The reward side of a refund or a lost dispute (spec Section
 * 9.8, OD-3 option (b)): a still-pending reward attached to a
 * refunded visit is canceled automatically with a `clawback`
 * referral_events row; a reward that already vested or was
 * claimed is never silently reversed, it is held for the owner's
 * review queue with the same clawback row explaining why.
 *
 * The transition is state-guarded under a row lock (Section 7.4
 * rule 2, CONC-04): whichever of clawback and claim commits
 * first wins; the loser sees the winner's state and follows the
 * other path. A canceled reward can never vest afterward, so
 * its queued reward-ready notification can never fire.
 */
class RewardClawback
{
    public const RULE = 'refund_clawback';

    /**
     * Claw back the reward attached to one refunded billing
     * event. Returns the outcome: 'canceled', 'review', or
     * 'none' (no reward, or a terminal state needing no action).
     */
    public function clawbackEvent(BillingEvent $event, string $actor = 'webhook', ?string $reason = null): string
    {
        $reward = Reward::query()->where('referral_id', $event->referral_id)->first();

        if ($reward === null) {
            return 'none';
        }

        return DB::transaction(function () use ($reward, $actor, $reason) {
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === Reward::STATE_PENDING_VESTING) {
                $locked->forceFill([
                    'state' => Reward::STATE_DENIED,
                    'hold' => false,
                    // BREAKER D-2 close (two-key Finding 3): a
                    // clawback cancel IS a deny, so it stamps the
                    // resolution like every other deny writer.
                    // Without the stamp, releaseReward()'s
                    // resolved-row guard did not fire for this
                    // branch and a stray release could flip the
                    // visit's held BillingEvent back to billable.
                    'hold_resolved_at' => now(),
                ])->save();

                ReferralEvent::record('clawback', $actor, $locked->business, $locked->referral, $locked, $locked->membership, [
                    'outcome' => 'canceled',
                    'reason' => $reason,
                ]);

                return 'canceled';
            }

            if (in_array($locked->state, [Reward::STATE_AVAILABLE, Reward::STATE_CLAIMED], true)) {
                $rules = $locked->hold_rules ?? [];

                if (in_array(self::RULE, $rules, true)) {
                    // Already surfaced for this clawback; a
                    // replayed delivery changes nothing (EC-28).
                    return 'review';
                }

                $rules[] = self::RULE;

                $locked->forceFill([
                    'hold' => true,
                    'hold_rules' => $rules,
                    'held_at' => $locked->held_at ?? now(),
                    // A NEW hold episode (BREAKER D-2): any
                    // earlier review resolution no longer governs
                    // this hold, so its stamp clears.
                    'hold_resolved_at' => null,
                    'hold_resolved_by' => null,
                ])->save();

                ReferralEvent::record('clawback', $actor, $locked->business, $locked->referral, $locked, $locked->membership, [
                    'outcome' => 'review',
                    'reason' => $reason,
                ]);

                return 'review';
            }

            return 'none';
        });
    }
}
