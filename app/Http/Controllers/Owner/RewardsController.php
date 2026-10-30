<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\SendRewardReadySms;
use App\Models\AuditLog;
use App\Models\Reward;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Rewards surface (spec Section 5.4): this business's rewards
 * under the state filters. The reward text shown is always the
 * mint-time snapshot, never the current settings text. The Text
 * column is the notification ledger state; a failed text can be
 * sent again, which re-enters the same job (which re-checks the
 * platform opt-out before sending).
 */
class RewardsController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const FILTERS = [
        'all' => 'All',
        'pending_vesting' => 'On its way',
        'available' => 'Ready to claim',
        'claimed' => 'Claimed',
        'held' => 'Held',
        // M-4 (pass Section 2.8): time needs a visible bucket;
        // before this, expired rewards showed only under All.
        'expired' => 'Expired',
    ];

    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS)
            ? (string) $request->query('filter')
            : 'all';

        // Vesting is applied at every read (spec Section 7.2), so
        // counts and filters see post-transition states. Closure
        // expiry joins it at set level (truth sweep 2026-10-30,
        // item 3), so the available count and list agree with
        // what a claim read would do past the closure window.
        Reward::applyVestingForBusiness($business);
        Reward::applyClosureExpiryForBusiness($business);
        // M-4: the reward's own 12-month date joins the set-level
        // transitions, so counts (including the new Expired tab)
        // agree with what a claim read would do.
        Reward::applyTimeExpiryForBusiness($business);

        // Demo double exclusion (A-18 Finding 3): see the
        // referrals list; the helper adds the business flag
        // leg and the referral stamp leg to this scoping.
        $base = \App\Support\DemoExclusion::rewards(Reward::query()->where('business_id', $business->id));

        // The filters are presented as a partition of All, so
        // they are counted as one (T5-F2): a held reward lives
        // only under Held, whatever its state. The state buckets
        // therefore exclude held rows, in the counts and in the
        // lists alike. (A held reward's state can still flip to
        // available on read when its vesting date passes; the
        // hold, not the state, decides its tab.)
        $counts = [
            'all' => (clone $base)->count(),
            'pending_vesting' => (clone $base)->where('state', Reward::STATE_PENDING_VESTING)->where('hold', false)->count(),
            'available' => (clone $base)->readyToClaim()->count(),
            'claimed' => (clone $base)->where('state', Reward::STATE_CLAIMED)->where('hold', false)->count(),
            'held' => (clone $base)->where('hold', true)->count(),
            // Held-first partition rule intact: a held
            // closure-expired row stays under Held until
            // resolved, exactly as the other state buckets
            // exclude held rows.
            'expired' => (clone $base)->where('state', Reward::STATE_EXPIRED)->where('hold', false)->count(),
        ];

        $rewards = $base
            ->with(['membership.customer', 'referral.referredCustomer', 'business'])
            ->when($filter === 'held', fn ($query) => $query->where('hold', true))
            ->when($filter === 'available', fn ($query) => $query->readyToClaim())
            ->when(in_array($filter, ['pending_vesting', 'claimed', 'expired'], true), fn ($query) => $query->where('state', $filter)->where('hold', false))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('owner.rewards.index', [
            'business' => $business,
            'rewards' => $rewards,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => $counts,
        ]);
    }

    /**
     * Text again (REWL-03): only a failed notification can be
     * re-sent; a suppressed (opted-out) or delivered one cannot,
     * and the job re-checks the opt-out at send time regardless.
     */
    public function resend(Request $request, Reward $reward): RedirectResponse
    {
        $business = $request->user()->business;
        abort_unless($reward->business_id === $business->id, 404);

        if ($reward->notification_state !== 'failed') {
            return back()->withErrors(['reward' => 'This text cannot be sent again.']);
        }

        // FH-OB1 (outbound truth sweep OB-01): a resend asserts
        // the reward "is ready to be claimed", so it re-checks
        // claimability at press time with the same on-read
        // transitions the job applies at send time (closure
        // expiry, vesting). A reward denied, held (review or
        // cooling), closure-expired, or already claimed since
        // the failed text cannot be sent again; the failed state
        // stays as the record.
        $reward->applyClosureExpiry();
        // M-4: a time-expired reward refuses with the same
        // shipped string; no new copy is invented for it.
        $reward->applyTimeExpiry();
        $reward->applyVesting();

        if ($reward->state !== Reward::STATE_AVAILABLE || $reward->hold) {
            return back()->withErrors(['reward' => 'This text cannot be sent again.']);
        }

        $reward->forceFill(['notification_state' => 'queued'])->save();

        SendRewardReadySms::dispatch($reward->id);

        AuditLog::write('reward.notification_resent', $request->user(), [], $business, $reward);

        return back()->with('status', 'Text sent again.');
    }
}
