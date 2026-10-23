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
    ];

    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS)
            ? (string) $request->query('filter')
            : 'all';

        // Vesting is applied at every read (spec Section 7.2), so
        // counts and filters see post-transition states.
        Reward::applyVestingForBusiness($business);

        $base = Reward::query()->where('business_id', $business->id);

        $counts = [
            'all' => (clone $base)->count(),
            'pending_vesting' => (clone $base)->where('state', Reward::STATE_PENDING_VESTING)->count(),
            'available' => (clone $base)->where('state', Reward::STATE_AVAILABLE)->count(),
            'claimed' => (clone $base)->where('state', Reward::STATE_CLAIMED)->count(),
            'held' => (clone $base)->where('hold', true)->count(),
        ];

        $rewards = $base
            ->with(['membership.customer', 'referral.referredCustomer'])
            ->when($filter === 'held', fn ($query) => $query->where('hold', true))
            ->when(in_array($filter, ['pending_vesting', 'available', 'claimed'], true), fn ($query) => $query->where('state', $filter))
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

        $reward->forceFill(['notification_state' => 'queued'])->save();

        SendRewardReadySms::dispatch($reward->id);

        AuditLog::write('reward.notification_resent', $request->user(), [], $business, $reward);

        return back()->with('status', 'Text sent again.');
    }
}
