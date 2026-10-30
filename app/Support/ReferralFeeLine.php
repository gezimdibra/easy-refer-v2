<?php

namespace App\Support;

use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Referral;

/**
 * The per-visit fee line (RS2-1, Amendment 44, REFL-13): what
 * one visit costs the owner, stated on the Referral detail and
 * as a quiet subline on the Referrals list.
 *
 * STATE-TRUTH RULE (the build-critical fact): the line renders
 * from the settlement walk's outcome for the event
 * (VisitValuation::settle()['settled'][event_id]) — NEVER from
 * the stored billing_events.coverage alone, which is only the
 * mint-time default ('billable'/'held'): a row stored billable
 * may settle as free_credit or over_cap. The one exception
 * mirrors SettlementService::settleAndPersist()'s guard: an
 * event claimed by a BillingRecord in a settled state is ledger
 * history, so its PERSISTED coverage governs.
 *
 * The reason keys only off the settled coverage, never off
 * fee_cents === 0: a free-credit $0.00 (one-time signup gift)
 * and an over-cap $0.00 (the $99 cap promise working) are
 * different facts and read differently. A held visit's line
 * carries NO dollar figure: on release its fee is settled then
 * and can land on free credit, so promising $2.00 while held
 * would be a state-blind claim.
 */
final class ReferralFeeLine
{
    /**
     * Record states whose claimed events are ledger history
     * (the settleAndPersist guard set).
     *
     * @var list<string>
     */
    private const SETTLED_RECORD_STATES = [
        BillingRecord::STATE_SUCCEEDED,
        BillingRecord::STATE_REFUNDED,
        BillingRecord::STATE_PARTIALLY_REFUNDED,
        BillingRecord::STATE_DISPUTED,
    ];

    /**
     * @param  array{coverage: string, fee_cents: int}|null  $outcome  the walk's settled entry for $event
     * @return array{detail: string, list: ?string} detail = the referral detail line (every state);
     *                                              list = the Referrals list subline, null = render nothing
     */
    public static function present(Referral $referral, ?BillingEvent $event, ?array $outcome, ?string $recordState, Business $business, bool $closureVoided = false): array
    {
        if ($referral->state === Referral::STATE_PENDING) {
            // P1/P2: no billing event exists before redemption,
            // in either hold regime (r36 claim-time holds and
            // the r46 conversion agree: no visit, no fee yet).
            return [
                'detail' => 'Fee: No fee yet. A fee is set only when your friend visits and the visit counts.',
                'list' => null,
            ];
        }

        if ($referral->state === Referral::STATE_EXPIRED) {
            return [
                'detail' => 'Fee: $0.00. No visit happened, so there is no fee.',
                'list' => null,
            ];
        }

        if ($referral->state === Referral::STATE_VOIDED) {
            // TS3-01 (Sweep-03, Amendment 52): voided has two
            // writers: owner denial (denyReferral) and account
            // closure (closeAccount voids every pending referral
            // with reason account_closed). The denied-before-a-
            // visit cause [REFL-13c] is valid ONLY in the denial
            // sub-state; a closure-voided referral gets the
            // no-cause form [REFL-13c2], the same true sentence
            // the expired state carries. The fee half ($0.00, no
            // fee) is true in both sub-states.
            return [
                'detail' => $closureVoided
                    ? 'Fee: $0.00. No visit happened, so there is no fee.'
                    : 'Fee: $0.00. This referral was denied before a visit, so there is no fee.',
                'list' => null,
            ];
        }

        if ($referral->state !== Referral::STATE_REDEEMED || $event === null) {
            // Defensive only: a redeemed referral always carries
            // exactly one billing event (minted in the redemption
            // transaction; post-closure completions create and
            // bill nothing because they do not complete, r32), so
            // this branch is unreachable on shipped paths. If it
            // were ever reached, the true fee is $0.00: no event,
            // no fee.
            return [
                'detail' => 'Fee: $0.00.',
                'list' => 'Fee: $0.00',
            ];
        }

        $claimed = $event->billing_record_id !== null;

        if ($claimed && $recordState !== null && in_array($recordState, self::SETTLED_RECORD_STATES, true)) {
            $coverage = $event->coverage;
            $feeCents = $coverage === 'billable' ? (int) $event->amount_cents : 0;
        } elseif ($outcome !== null) {
            $coverage = $outcome['coverage'];
            $feeCents = (int) $outcome['fee_cents'];
        } else {
            $coverage = $event->coverage;
            $feeCents = $coverage === 'billable' ? (int) $event->amount_cents : 0;
        }

        return match ($coverage) {
            'billable' => [
                'detail' => $claimed
                    ? 'Fee: '.self::cents($feeCents).', on your monthly charge.'
                    : 'Fee: '.self::cents($feeCents).', in your next monthly charge.',
                'list' => 'Fee: '.self::cents($feeCents),
            ],
            'free_credit' => [
                'detail' => 'Fee: $0.00, one of your '.(int) config('billing.free_visits_at_signup', 5).' free visits.',
                'list' => 'Fee: $0.00, free visit',
            ],
            'over_cap' => [
                'detail' => 'Fee: $0.00, past your '.self::cap($business).' monthly cap.',
                'list' => 'Fee: $0.00, past the cap',
            ],
            'held' => [
                'detail' => 'No fee yet. This visit is waiting on your review. If you release it, its fee is settled then; if you deny it, there is never a fee.',
                'list' => 'Fee: held for review',
            ],
            'fraud_denied' => [
                // True in both sub-cases (denied from held, or
                // denied unheld): the line never claims a free
                // slot was returned, because excludeVisitFrom-
                // Billing restores one only when the event had
                // settled free_credit, and that prior coverage
                // is overwritten, not recorded.
                'detail' => 'Fee: $0.00. You denied this visit in review, so there is no fee.',
                'list' => 'Fee: $0.00',
            ],
            'refunded' => [
                'detail' => 'Fee: $0.00, refunded.',
                'list' => 'Fee: $0.00, refunded',
            ],
            'written_off' => [
                // FE-CANCEL-01: the sub-$1 residual written off
                // at program end; the visit counted, the fee was
                // never charged and never will be.
                'detail' => 'Fee: $0.00, written off when your program ended.',
                'list' => 'Fee: $0.00',
            ],
            default => [
                'detail' => 'Fee: $0.00.',
                'list' => 'Fee: $0.00',
            ],
        };
    }

    /** Cents-exact rendering, the billing surfaces' convention. */
    private static function cents(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    /** The plan cap, from the plan row (config fallback). */
    private static function cap(Business $business): string
    {
        $capCents = $business->currentPlan !== null && $business->currentPlan->monthly_cap_cents !== null
            ? (int) $business->currentPlan->monthly_cap_cents
            : (int) config('billing.monthly_cap_cents', 9900);

        return Money::usd($capCents);
    }
}
