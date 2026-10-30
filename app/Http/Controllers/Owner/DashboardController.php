<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ActionCenterDismissal;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use App\Services\Owner\ActionCenter;
use App\Support\SetupChecklist;
use App\Support\StarterPacks;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The owner Overview (spec Section 5.1, design amendment Section
 * 6.4.1): greeting, the one action card, the starter pack while
 * rewards are unset, the four action tiles, the standing counters
 * (visible only once the first enrollment exists, Section 6.4.1
 * zero state), the cumulative-customers chart with its table
 * toggle, the weekly top referrers, the Value panel (VAL-01 to
 * VAL-03), and the setup checklist (Section 5.8). Every number is
 * computed from live rows at request time; nothing is cached,
 * sampled, or illustrative.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, VisitValuation $valuation, SettlementService $settlement): View
    {
        $user = $request->user();
        $business = $user->business;
        $timezone = $business->timezone ?: 'America/New_York';

        // On-read transitions first (spec Section 7.2): every
        // counter below must read post-transition state, not the
        // state the last sweeper happened to leave behind.
        Referral::applyExpiryForBusiness($business);
        Reward::applyVestingForBusiness($business);
        Reward::applyClosureExpiryForBusiness($business);
        // M-4: an expired reward leaves the waiting tile at the
        // same read that expires it (pass Section 2.2).
        Reward::applyTimeExpiryForBusiness($business);

        $todayStart = now($timezone)->startOfDay()->utc();
        $yesterdayStart = now($timezone)->subDay()->startOfDay()->utc();

        // B7 M-2: the chart's pluck of every redeemed referral's
        // redeemed_at (below) is a superset of the tiles' today
        // and yesterday windows, so it runs once, up here, and
        // both tile counts are derived from it instead of being
        // counted by two further queries. Stored timestamps are
        // UTC wall time in Y-m-d H:i:s format, so comparing the
        // stored strings against the same bounds the count
        // queries used, formatted the same way, is an identical
        // comparison — never a local-time string comparison.
        // Demo double exclusion (A-18 Finding 3): the
        // business_id scoping is the flag leg (the owner's own
        // business; the demo business has no owner account),
        // and the source condition is the stamp leg. Every
        // referral and reward read below routes through
        // App\Support\DemoExclusion for the same two legs.
        $redeemedDates = DB::table('referrals')
            ->where('business_id', $business->id)
            ->where('source', '!=', 'demo')
            ->where('state', Referral::STATE_REDEEMED)
            ->whereNotNull('redeemed_at')
            ->orderBy('redeemed_at')
            ->pluck('redeemed_at');

        $todayStartText = $todayStart->format('Y-m-d H:i:s');
        $yesterdayStartText = $yesterdayStart->format('Y-m-d H:i:s');
        $referralsToday = 0;
        $referralsYesterday = 0;
        foreach ($redeemedDates as $redeemedAt) {
            $redeemedAtText = (string) $redeemedAt;
            if ($redeemedAtText >= $todayStartText) {
                $referralsToday++;
            } elseif ($redeemedAtText >= $yesterdayStartText) {
                $referralsYesterday++;
            }
        }

        // B7 M-1: the held row sets are read by three consumers
        // in this request — the tiles' review_waiting count, the
        // shell's review-queue badge, and the Action Center's
        // reviewHolds row (which ages and fingerprints these
        // exact rows). Fetch both collections once, here, after
        // the on-read transitions and before anything renders,
        // and hand them over below; each consumer's number is
        // these collections' counts, so they cannot disagree.
        $heldReferrals = \App\Support\DemoExclusion::referrals(Referral::query())
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->get(['id', 'held_at']);
        $heldRewards = \App\Support\DemoExclusion::rewards(Reward::query())
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->get(['id', 'held_at']);

        $tiles = [
            'scans_today' => \App\Support\DemoExclusion::referralEvents(ReferralEvent::query())
                ->where('business_id', $business->id)
                ->where('event_type', 'scan')
                ->where('created_at', '>=', $todayStart)
                ->count(),
            'referrals_today' => $referralsToday,
            'referrals_yesterday' => $referralsYesterday,
            'rewards_waiting' => \App\Support\DemoExclusion::rewards(Reward::query())
                ->where('business_id', $business->id)
                ->readyToClaim()
                ->count(),
            'review_waiting' => $heldReferrals->count() + $heldRewards->count(),
        ];

        $hasEnrollments = Membership::query()->where('business_id', $business->id)->exists();

        // The standing counters (spec Section 5.1 definitions):
        // completed means state redeemed only; pending is its own
        // number and is never merged into any total; new customers
        // is the trailing 5-day window; promotional messages sent
        // is delivered campaign recipients.
        $counters = [
            'completed_total' => \App\Support\DemoExclusion::referrals(Referral::query())
                ->where('business_id', $business->id)
                ->where('state', Referral::STATE_REDEEMED)
                ->count(),
            'pending_total' => \App\Support\DemoExclusion::referrals(Referral::query())
                ->where('business_id', $business->id)
                ->where('state', Referral::STATE_PENDING)
                ->count(),
            'new_customers' => Membership::query()
                ->where('business_id', $business->id)
                ->whereNull('removed_at')
                ->where('created_at', '>=', now()->subDays(5))
                ->count(),
            'total_customers' => Membership::query()
                ->where('business_id', $business->id)
                ->whereNull('removed_at')
                ->count(),
            'rewards_issued' => \App\Support\DemoExclusion::rewards(Reward::query())
                ->where('business_id', $business->id)
                ->count(),
            'rewards_claimed' => \App\Support\DemoExclusion::rewards(Reward::query())
                ->where('business_id', $business->id)
                ->where('state', Reward::STATE_CLAIMED)
                ->count(),
            'promo_sent' => SmsMessage::query()
                ->where('business_id', $business->id)
                ->where('purpose', 'campaign')
                ->where('delivery_state', 'delivered')
                ->count(),
        ];

        // The chart (design Section 6.4 item 6): cumulative
        // referred customers, meaning cumulative completed
        // referrals, over the chosen window. Points are computed
        // per local day in the business timezone; for a windowed
        // range the line starts from the true total reached before
        // the window, so the window shows growth on top of
        // history, never a reset.
        $range = in_array($request->query('range'), ['7', '30', 'all'], true)
            ? (string) $request->query('range')
            : '30';

        // Day bucketing runs over raw stored timestamps with a
        // single shared zone: hydrating a Carbon per redeemed
        // referral and re-zoning the copy cost ~43us a row, which
        // at 10,000-referral volume was ~300 ms of the dashboard
        // (Sprint 6, protocol Section 2.5). Stored timestamps are
        // UTC wall time; the bucket is the business-local date,
        // identical to the Carbon computation it replaces.
        $perDay = [];
        $zone = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');
        // $redeemedDates was plucked once above the tiles (B7
        // M-2); this loop buckets the same collection per day.
        foreach ($redeemedDates as $redeemedAt) {
            $day = (new DateTimeImmutable((string) $redeemedAt, $utc))->setTimezone($zone)->format('Y-m-d');
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }

        $today = now($timezone)->toDateString();
        $firstDay = $range === 'all'
            ? (array_key_first($perDay) ?? $today)
            : now($timezone)->subDays($range === '7' ? 6 : 29)->toDateString();

        $running = 0;
        foreach ($perDay as $day => $count) {
            if ($day < $firstDay) {
                $running += $count;
            }
        }

        $chart = [];
        $cursor = Carbon::parse($firstDay, $timezone);
        $end = Carbon::parse($today, $timezone);
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $running += $perDay[$day] ?? 0;
            $chart[] = ['date' => $day, 'label' => $cursor->format('M j'), 'total' => $running];
            $cursor->addDay();
        }

        // Weekly top referrers (spec Section 5.1): this business's
        // memberships ranked by referrals completed in the last 7
        // days. Only memberships with at least one completion this
        // week can rank.
        //
        // The ranking runs in two steps (F6). Step one aggregates
        // the week's redeemed referrals grouped by the referrer
        // alone; step two hydrates those memberships, attaches the
        // counts, and finishes the ordering in PHP. The Sprint 6
        // shape was a single aggregate join selecting memberships.*
        // under a GROUP BY over the join; MariaDB's
        // ONLY_FULL_GROUP_BY rejects that with error 1055 even
        // though SQLite accepts it, so on the production engine
        // every authenticated dashboard load returned 500 while
        // the suite stayed green (hidden_files/redesign/
        // gate3-log-forensics.md). Grouping by the single selected
        // key is legal on every engine. The two-step shape keeps
        // the Sprint 6 performance property that motivated the
        // join: a constant number of indexed queries and no
        // per-membership correlated subquery, the shape that cost
        // ~11.6 s at 10,000-referral volume (Sprint 6, protocol
        // Section 2.5). Ordering finishes in PHP over the week's
        // referrers, a set bounded by the membership count and
        // never the referral count, so the created_at tie-break
        // applies before the limit exactly as the old ORDER BY
        // did (a blind LIMIT 5 on the counts alone would pick an
        // arbitrary member of a boundary tie).
        $weeklyCounts = \App\Support\DemoExclusion::referrals(Referral::query())
            ->whereIn('referrer_membership_id', Membership::query()
                ->where('business_id', $business->id)
                ->whereNull('removed_at')
                ->select('id'))
            ->where('state', Referral::STATE_REDEEMED)
            ->where('redeemed_at', '>=', now()->subDays(7))
            ->groupBy('referrer_membership_id')
            ->select('referrer_membership_id')
            ->selectRaw('count(id) as weekly_referrals')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->referrer_membership_id => (int) $row->weekly_referrals]);

        $topReferrers = $weeklyCounts->isEmpty()
            ? collect()
            : Membership::query()
                ->whereIn('id', $weeklyCounts->keys())
                ->with('customer')
                ->get()
                ->each(fn (Membership $membership) => $membership->setAttribute(
                    'weekly_referrals',
                    $weeklyCounts[$membership->id],
                ))
                // PHP sorts are stable, so sorting by the
                // tie-break first and the count second yields
                // (weekly_referrals desc, created_at asc).
                ->sortBy(fn (Membership $membership) => $membership->created_at->getTimestamp())
                ->sortByDesc('weekly_referrals')
                ->take(5)
                ->values();

        $packs = StarterPacks::forType($business->business_type);

        // The checklist computation is shared with the Action
        // Center (RK-01) so the two surfaces can never disagree
        // about how far setup has come.
        $checklist = SetupChecklist::for($business, $user);

        // B7 M-5: the dismissal state is read once, as the full
        // per-item fingerprint map. This panel's dismissed flag
        // is the map's setup_checklist entry, and the Action
        // Center's visibleItems() plucks this same map when it
        // runs in this request instead of querying it again.
        $dismissals = ActionCenterDismissal::query()
            ->where('business_id', $business->id)
            ->pluck('state_fingerprint', 'item_key');
        $dismissedFingerprint = $dismissals->get('setup_checklist');
        $checklistDismissed = $dismissedFingerprint !== null
            && $dismissedFingerprint === ActionCenter::setupFingerprint($checklist);

        // Hand this request's computations to the shell's view
        // composers and the Action Center instead of running
        // them twice: the checklist (RK-01), the held
        // collections and their count (B7 M-1), the dismissal
        // map (B7 M-5), and the counters' redeemed total as the
        // has-any-redemption fact (B7 M-7). These are plain
        // statements on purpose: setting an attribute inside a
        // boolean expression lets an empty map short-circuit the
        // chain and silently skip the hand-over (the B7
        // profiling lane's documented trap).
        $request->attributes->set('action_center_checklist', $checklist);
        $request->attributes->set('action_center_held_referrals', $heldReferrals);
        $request->attributes->set('action_center_held_rewards', $heldRewards);
        $request->attributes->set('review_queue_count', $heldReferrals->count() + $heldRewards->count());
        $request->attributes->set('action_center_dismissals', $dismissals);
        $request->attributes->set('has_redeemed_referral', $counters['completed_total'] > 0);

        return view('owner.dashboard', [
            'business' => $business,
            'tiles' => $tiles,
            'counters' => $counters,
            'hasEnrollments' => $hasEnrollments,
            // The first-enrollment transition state (design
            // 6.4.1 item 8): someone has joined, no verified
            // visit has landed yet.
            'firstCustomer' => $hasEnrollments && $counters['completed_total'] === 0,
            'value' => $valuation->forMonth($business, now()),
            'valueAllTime' => $allTimeSettlement = $valuation->settle($business),
            // RK-03's forecast line renders from the accrued
            // preview of THIS settlement pass: the settlement
            // runs once per dashboard load, not once per panel.
            // Train A2: the preview is the gated FORECAST
            // preview (the visit leg of this same settlement
            // pass plus the text leg), so the Overview quotes
            // the same combined total as Billing and the email.
            // The gate's card fact comes from the checklist
            // computed above (its card_on_file entry IS the
            // defaultFor check), and both text figures come
            // from one conditional-sum query, so the train
            // adds exactly one measured query to this page.
            'forecastPreview' => \App\Support\BillingForecast::gatedPreview(
                $business,
                $allTimeSettlement,
                $checklist['card_on_file'],
                ($textFigures = $settlement->textFigures($business, now()))['all'],
            ),
            // VAL-02's conditional text line: this month's
            // accrued text charges, the same UTC month scope
            // as the panel's visit figure, from the same
            // textFigures query as the forecast leg above.
            'monthTextCents' => $textFigures['month'],
            'emailVerified' => $user->hasVerifiedEmail(),
            'showStarterPack' => ! $business->hasFriendRewardText() && $packs !== [],
            'packs' => $packs,
            'checklist' => $checklist,
            // G6 (coherence Phase 2): ONE dismissal domain.
            // The card is dismissed exactly while the Action
            // Center's setup row is dismissed — the stored
            // fingerprint matches the checklist's current
            // composition fingerprint. The legacy
            // checklist_dismissed_at column no longer decides
            // anything (pre-unification dismissals stored in
            // it simply stop matching, the safe direction: the
            // card resurfaces once).
            'checklistDismissed' => $checklistDismissed,
            // M-4 DASH-13 fix (Amendment 71 addendum, [M4-DASH-01]):
            // the closed banner's in-window line [DASH-13 closed
            // banner v3] names the exact closed_at + 30 days date
            // unconditionally, which is false for a live reward
            // whose own use-by date falls before that date. Same
            // conditional branch as [M4-ACCT-04] on the account
            // page, against the ACTUAL window end, computed after
            // the on-read transitions above so it reads live
            // state only; past the window the ended form needs
            // none (closure expiry has ended claimability for
            // every live reward, held or not).
            'closedRewardOwnDateInsideWindow' => $business->isClosed() && $business->closed_at !== null && ! $business->closureWindowEnded()
                ? \App\Support\DemoExclusion::rewards(Reward::query())
                    ->where('business_id', $business->id)
                    ->whereIn('state', [Reward::STATE_PENDING_VESTING, Reward::STATE_AVAILABLE])
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', $business->closed_at->copy()->addDays(30))
                    ->exists()
                : false,
            'chart' => $chart,
            'chartRange' => $range,
            'chartTotal' => $counters['completed_total'],
            'topReferrers' => $topReferrers,
        ]);
    }

    /**
     * Dismiss the setup checklist (spec Section 5.8: per business,
     * dismissible, and resettable from Settings). The dismissal is
     * server state, not a browser preference, so it holds on every
     * device the owner signs in from.
     *
     * Phase 2 (G6): the dismissal is stored in the Action
     * Center's dismissal domain at the checklist's current
     * composition fingerprint — the same record that hides the
     * Action Center's setup row — so the two surfaces can never
     * disagree, and the Action Center's "Finish setup" link
     * never lands on a checklist-less dashboard.
     */
    public function dismissChecklist(Request $request, ActionCenter $center): RedirectResponse
    {
        $user = $request->user();
        $business = $user->business;

        $center->dismiss($business, $user, 'setup_checklist');

        // OV-5 (post-r26): the Action Center's setup source
        // exists only while a step is open, so dismissing the
        // finished checklist's slim row has no source row to
        // record against. Record that dismissal directly, at
        // the complete checklist's own fingerprint in the same
        // G6 domain: the slim row hides, the Settings reset
        // reopens it, and a step reopening changes the
        // fingerprint and resurfaces it, exactly like an
        // open-step dismissal.
        $checklist = SetupChecklist::for($business, $user);
        // ON-SET-2: the slim row renders on REQUIRED completion,
        // so its direct dismissal keys on the same definition —
        // an unset optional review link does not strand it.
        if (SetupChecklist::requiredComplete($checklist)) {
            ActionCenterDismissal::query()->updateOrCreate(
                ['business_id' => $business->id, 'item_key' => 'setup_checklist'],
                ['state_fingerprint' => ActionCenter::setupFingerprint($checklist)],
            );
        }

        AuditLog::write('business.checklist_dismissed', $user, [], $business);

        return redirect()->route('dashboard');
    }
}
