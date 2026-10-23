<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Services\Billing\VisitValuation;
use App\Support\StarterPacks;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, VisitValuation $valuation): View
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
        // is delivered campaign recipients (campaigns ship in a
        // later sprint, so the definition reads zero until then).
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

        $perDay = [];
        $redeemedDates = Referral::query()
            ->where('business_id', $business->id)
            ->where('state', Referral::STATE_REDEEMED)
            ->whereNotNull('redeemed_at')
            ->orderBy('redeemed_at')
            ->pluck('redeemed_at');

        foreach ($redeemedDates as $redeemedAt) {
            $day = $redeemedAt->copy()->setTimezone($timezone)->toDateString();
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
        // days.
        $topReferrers = Membership::query()
            ->where('business_id', $business->id)
            ->whereNull('removed_at')
            ->with('customer')
            ->withCount(['referralsMade as weekly_referrals' => function ($query) {
                $query->where('state', Referral::STATE_REDEEMED)
                    ->where('redeemed_at', '>=', now()->subDays(7));
            }])
            ->orderByDesc('weekly_referrals')
            ->orderBy('created_at')
            ->limit(5)
            ->get()
            ->filter(fn (Membership $membership) => $membership->weekly_referrals > 0)
            ->values();

        $packs = StarterPacks::forType($business->business_type);

        $checklist = [
            'email_verified' => $user->hasVerifiedEmail(),
            'reward_text' => $business->friend_reward_text !== null && trim((string) $business->friend_reward_text) !== '',
            'poster_downloaded' => ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', 'poster_downloaded')
                ->exists(),
            // The card-on-file signal that exists in v2 today is
            // the Square customer record; the card capture surface
            // itself ships with billing (Phase 4).
            'card_on_file' => $business->square_customer_id !== null,
        ];

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
            'valueAllTime' => $valuation->settle($business),
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
