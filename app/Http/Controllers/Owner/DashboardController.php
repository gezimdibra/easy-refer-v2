<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
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

        $todayStart = now($timezone)->startOfDay()->utc();
        $yesterdayStart = now($timezone)->subDay()->startOfDay()->utc();

        $tiles = [
            'scans_today' => ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', 'scan')
                ->where('created_at', '>=', $todayStart)
                ->count(),
            'referrals_today' => Referral::query()
                ->where('business_id', $business->id)
                ->where('state', Referral::STATE_REDEEMED)
                ->where('redeemed_at', '>=', $todayStart)
                ->count(),
            'referrals_yesterday' => Referral::query()
                ->where('business_id', $business->id)
                ->where('state', Referral::STATE_REDEEMED)
                ->where('redeemed_at', '>=', $yesterdayStart)
                ->where('redeemed_at', '<', $todayStart)
                ->count(),
            'rewards_waiting' => Reward::query()
                ->where('business_id', $business->id)
                ->where('state', Reward::STATE_AVAILABLE)
                ->count(),
            'review_waiting' => Referral::query()
                    ->where('business_id', $business->id)
                    ->where('hold', true)
                    ->count()
                + Reward::query()
                    ->where('business_id', $business->id)
                    ->where('hold', true)
                    ->count(),
        ];

        $hasEnrollments = Membership::query()->where('business_id', $business->id)->exists();

        // The standing counters (spec Section 5.1 definitions):
        // completed means state redeemed only; pending is its own
        // number and is never merged into any total; new customers
        // is the trailing 5-day window; promotional messages sent
        // is delivered campaign recipients.
        $counters = [
            'completed_total' => Referral::query()
                ->where('business_id', $business->id)
                ->where('state', Referral::STATE_REDEEMED)
                ->count(),
            'pending_total' => Referral::query()
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
            'rewards_issued' => Reward::query()
                ->where('business_id', $business->id)
                ->count(),
            'rewards_claimed' => Reward::query()
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
        $redeemedDates = DB::table('referrals')
            ->where('business_id', $business->id)
            ->where('state', Referral::STATE_REDEEMED)
            ->whereNotNull('redeemed_at')
            ->orderBy('redeemed_at')
            ->pluck('redeemed_at');

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
        $weeklyCounts = Referral::query()
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

        // The shell's Action Center badge reads the same
        // checklist for its setup row; hand this request's
        // computation over instead of running it twice.
        $request->attributes->set('action_center_checklist', $checklist);

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
            'forecastPreview' => $settlement->accruedPreview($business, $allTimeSettlement),
            'emailVerified' => $user->hasVerifiedEmail(),
            'showStarterPack' => $business->friend_reward_text === null && $packs !== [],
            'packs' => $packs,
            'checklist' => $checklist,
            'checklistDismissed' => $business->checklist_dismissed_at !== null,
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
     */
    public function dismissChecklist(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        $business->forceFill(['checklist_dismissed_at' => now()])->save();

        AuditLog::write('business.checklist_dismissed', $request->user(), [], $business);

        return redirect()->route('dashboard');
    }
}
