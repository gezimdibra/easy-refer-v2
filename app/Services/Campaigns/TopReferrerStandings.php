<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use Illuminate\Support\Carbon;

/**
 * ID-02 (Amendment 48): the ONE standings read for
 * top-referrer recognition. The card render, draft creation,
 * the confirm render, and the send-time recompute all call
 * this, so the four surfaces can never disagree.
 *
 * THE COUNTING LAW: a referral counts toward a referrer's
 * standing for a month iff, at the moment of computation,
 * (1) referrals.state = redeemed with redeemed_at inside the
 * month bucketed in the BUSINESS's timezone, (2) a billing
 * event exists with coverage NOT IN (held, fraud_denied,
 * refunded), and (3) the referral's reward is not held and
 * not denied. Vesting never blocks. memberships.referral_count
 * is NEVER read here: it increments at claim creation and
 * would crown referrers for visits that never happened.
 */
final class TopReferrerStandings
{
    /**
     * The standings month a card may act on right now: the
     * previous complete business-local calendar month, and
     * only while today (business-local) is inside the card
     * window. Null outside the window.
     */
    public static function currentStandingsMonth(Business $business): ?string
    {
        $local = $business->localTime(now());

        if ((int) $local->day > (int) config('campaigns.top_referrer_window_days', 14)) {
            return null;
        }

        return $local->copy()->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * Per-membership settled counts for one business-local
     * month (YYYY-MM), highest first.
     *
     * @return array<int, int> membership_id => settled count
     */
    public static function countsForMonth(Business $business, string $month): array
    {
        [$start, $end] = self::monthWindowUtc($business, $month);

        $rows = Referral::query()
            ->where('referrals.business_id', $business->id)
            ->where('referrals.state', Referral::STATE_REDEEMED)
            ->whereNotNull('referrals.redeemed_at')
            ->where('referrals.redeemed_at', '>=', $start)
            ->where('referrals.redeemed_at', '<', $end)
            ->join('billing_events', 'billing_events.referral_id', '=', 'referrals.id')
            ->whereNotIn('billing_events.coverage', ['held', 'fraud_denied', 'refunded'])
            ->leftJoin('rewards', 'rewards.referral_id', '=', 'referrals.id')
            ->where(function ($query) {
                $query->whereNull('rewards.id')
                    ->orWhere(function ($inner) {
                        $inner->where('rewards.hold', false)
                            ->where('rewards.state', '!=', 'denied');
                    });
            })
            ->selectRaw('referrals.referrer_membership_id as membership_id, COUNT(*) as settled')
            ->groupBy('referrals.referrer_membership_id')
            ->orderByDesc('settled')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->membership_id] = (int) $row->settled;
        }

        return $counts;
    }

    /**
     * The crowning read: the sendable leader set for a month,
     * or null when nobody is crowned. Nobody is crowned when
     * the highest settled count is below the floor, when there
     * are no settled visits, or when ANY leader is not
     * sendable under the campaign audience rule (the title
     * never passes to #2).
     *
     * @return array{month: string, count: int, form: string, leaders: list<Membership>}|null
     */
    public static function leadersForMonth(Business $business, string $month): ?array
    {
        $counts = self::countsForMonth($business, $month);

        if ($counts === []) {
            return null;
        }

        $max = max($counts);

        if ($max < (int) config('campaigns.top_referrer_floor', 3)) {
            return null;
        }

        $leaderIds = array_keys(array_filter($counts, fn (int $c) => $c === $max));
        sort($leaderIds);

        $sendableIds = CampaignAudience::forBusiness($business)['memberships']
            ->map(fn (Membership $m) => $m->id)
            ->all();

        foreach ($leaderIds as $id) {
            if (! in_array($id, $sendableIds, true)) {
                return null;
            }
        }

        $leaders = Membership::query()
            ->whereIn('id', $leaderIds)
            ->with('customer')
            ->orderBy('id')
            ->get()
            ->all();

        return [
            'month' => $month,
            'count' => $max,
            'form' => count($leaders) > 1 ? 'tie' : 'sole',
            'leaders' => $leaders,
        ];
    }

    /**
     * One recipient's live fingerprint for a month: their
     * settled count and whether they currently stand as a
     * (co-)leader, or null when they are not a sendable leader
     * at or above the floor (the send-refusal state).
     *
     * @return array{count: int, form: string}|null
     */
    public static function fingerprintFor(Business $business, string $month, int $membershipId): ?array
    {
        $leaders = self::leadersForMonth($business, $month);

        if ($leaders === null) {
            return null;
        }

        foreach ($leaders['leaders'] as $leader) {
            if ($leader->id === $membershipId) {
                return ['count' => $leaders['count'], 'form' => $leaders['form']];
            }
        }

        return null;
    }

    /** Whether a recognition text already sent for this (month, recipient). */
    public static function alreadySent(Business $business, string $month, int $membershipId): bool
    {
        return \App\Models\SmsCampaign::query()
            ->where('business_id', $business->id)
            ->where('provenance', \App\Models\SmsCampaign::PROVENANCE_TOP_REFERRER)
            ->where('standings_month', $month)
            ->where('recipient_membership_id', $membershipId)
            ->whereIn('status', [\App\Models\SmsCampaign::STATUS_SENT, \App\Models\SmsCampaign::STATUS_SENDING])
            ->exists();
    }

    /** The TR-03..TR-06 template body for a leader, from a live fingerprint. */
    public static function templateBody(Business $business, Membership $leader, string $month, int $count, string $form): string
    {
        $monthName = Carbon::createFromFormat('Y-m', $month)->format('F');
        $first = trim((string) ($leader->customer?->first_name ?? ''));
        $name = $first !== '' ? ', '.$first : '';

        return $form === 'tie'
            ? "Thank you{$name}! You were one of our top referrers in {$monthName}: {$count} friends you sent us came in, tied for the most."
            : "Thank you{$name}! You were our top referrer in {$monthName}: {$count} friends you sent us came in, more than anyone else.";
    }

    /** The owner's display name for a leader (card + lock line), per the section 1.6 fallback chain. */
    public static function displayName(Membership $leader): string
    {
        $customer = $leader->customer;
        $full = trim((string) ($customer?->fullName() ?? ''));

        if ($full !== '') {
            return $full;
        }

        $first = trim((string) ($customer?->first_name ?? ''));

        if ($first !== '') {
            return $first;
        }

        $phone = (string) ($customer?->phone_e164 ?? '');

        return 'the customer ending in '.substr($phone, -4);
    }

    /**
     * The month's UTC instant window: business-local midnight
     * on the 1st to business-local midnight on the 1st of the
     * next month, expressed as UTC instants for redeemed_at.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function monthWindowUtc(Business $business, string $month): array
    {
        $zone = is_string($business->timezone) && in_array($business->timezone, timezone_identifiers_list(), true)
            ? $business->timezone
            : (string) config('app.timezone');

        $startLocal = Carbon::createFromFormat('Y-m-d', $month.'-01', $zone)->startOfDay();
        $endLocal = $startLocal->copy()->addMonthNoOverflow();

        return [$startLocal->copy()->setTimezone('UTC'), $endLocal->copy()->setTimezone('UTC')];
    }
}
