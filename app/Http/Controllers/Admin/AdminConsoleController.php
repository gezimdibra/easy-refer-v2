<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The platform console (spec Section 15): the platform's own
 * tooling, never a tenant surface. Phase 3 lands the foundation:
 * the overview rollups, the business list and detail, the risk
 * feed read model, hold oversight, the suspension lever, and the
 * audit viewer. Every console read and every action writes an
 * audit row (Section 15 rule: every cross-business read and every
 * console action is recorded), with the acting admin as actor.
 * Nothing here can act inside a tenant's session or change a
 * tenant's data beyond the suspension lever itself.
 */
class AdminConsoleController extends Controller
{
    public function home(Request $request): View
    {
        $admin = $request->user('admin');

        // The demo business (Amendment 63, Section 13.2) is
        // excluded from every platform count and aggregate on
        // this console. BOTH legs, per surface (A-18 Finding
        // 3, App\Support\DemoExclusion): business counts carry
        // the flag leg (they count businesses, the flag is
        // their stamp), and every visit, hold and aggregate
        // query routes through the helper, which applies the
        // flag leg AND the source/coverage stamp leg, so a
        // stamp mismatch row counts nowhere. The demo's own
        // readout lives in its event ledger, counted as demo,
        // forever separate.
        $data = [
            'businessTotal' => Business::query()->where('is_demo', false)->count(),
            'businessActive' => Business::query()->where('is_demo', false)->where('status', 'active')->count(),
            'businessDisabled' => Business::query()->where('is_demo', false)->where('status', 'disabled')->count(),
            'businessClosed' => Business::query()->where('is_demo', false)->where('status', 'closed')->count(),
            'businessNewThisWeek' => Business::query()->where('is_demo', false)->where('created_at', '>=', now()->subDays(7))->count(),
            'visitsToday' => \App\Support\DemoExclusion::referrals(Referral::query())
                ->where('state', Referral::STATE_REDEEMED)
                ->where('redeemed_at', '>=', now()->startOfDay())
                ->count(),
            'openHolds' => \App\Support\DemoExclusion::referrals(Referral::query())->where('hold', true)->count()
                + \App\Support\DemoExclusion::rewards(Reward::query())->where('hold', true)->count(),
            'recentAudit' => AuditLog::query()->orderByDesc('id')->limit(10)->get(),
        ];

        AuditLog::write('admin.console_view', $admin, ['surface' => 'home']);

        return view('admin.home', $data);
    }

    public function businesses(Request $request): View
    {
        $admin = $request->user('admin');
        $search = trim((string) $request->query('q', ''));

        $businesses = Business::query()
            ->where('is_demo', false)
            ->with('owner')
            ->withCount('memberships')
            ->withCount(['referrals as completed_referrals' => function ($query) {
                // Stamp leg on top of this list's flag leg
                // (A-18 Finding 3): a source='demo' referral
                // on a real business is not a completed visit.
                $query->where('source', '!=', 'demo')
                    ->where('state', Referral::STATE_REDEEMED);
            }])
            ->when($search !== '', function ($query) use ($search) {
                $digits = preg_replace('/\D+/', '', $search);

                // Literal matching (XT-46): the input is text,
                // never a LIKE pattern. The phone clause only
                // joins when the search carries digits at all; a
                // digit-less query must not degenerate into a
                // match-everything '%%' phone pattern (spec
                // verifier finding 4).
                $query->where(function ($inner) use ($search, $digits) {
                    $inner->whereRaw(Search::likeClause('name'), [Search::contains($search)])
                        ->orWhereHas('owner', fn ($ownerQuery) => $ownerQuery->whereRaw(Search::likeClause('email'), [Search::contains($search)]));

                    if ((string) $digits !== '') {
                        $inner->orWhere('contact_phone_e164', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        AuditLog::write('admin.console_view', $admin, ['surface' => 'businesses', 'search' => $search]);

        return view('admin.businesses', [
            'businesses' => $businesses,
            'search' => $search,
        ]);
    }

    public function business(Request $request, Business $business, \App\Services\Billing\VisitValuation $valuation): View
    {
        // The demo business (Amendment 63) has no admin surface.
        if ($business->isDemo()) {
            abort(404);
        }

        $admin = $request->user('admin');

        $business->load('owner');
        $plan = $business->currentPlan;

        // Billing event counts (ADMC-05): the events themselves
        // are verified-visit records; their coverage (billable,
        // free credit, over cap) is the settlement the valuation
        // service computes, so the counts come from the same
        // settle the owner's Value panel reads. Refund and
        // clawback records do not exist until the billing sprint;
        // the counts read zero because there are no such rows.
        $settled = $valuation->settle($business)['settled'];
        $coverageCounts = collect($settled)->countBy('coverage');

        AuditLog::write('admin.console_view', $admin, ['surface' => 'business_detail'], $business);

        return view('admin.business', [
            'business' => $business,
            'planName' => $plan?->name,
            'usage' => [
                'customers' => $business->memberships()->count(),
                'referrals_completed' => $business->referrals()->where('state', Referral::STATE_REDEEMED)->count(),
                'referrals_pending' => $business->referrals()->where('state', Referral::STATE_PENDING)->count(),
                'rewards_issued' => $business->rewards()->count(),
                'rewards_claimed' => $business->rewards()->where('state', Reward::STATE_CLAIMED)->count(),
                'open_holds' => $business->referrals()->where('hold', true)->count()
                    + $business->rewards()->where('hold', true)->count(),
            ],
            'billingCounts' => [
                'billable' => (int) ($coverageCounts['billable'] ?? 0),
                'free' => (int) ($coverageCounts['free_credit'] ?? 0),
                // "Charges" counts visits that will be charged
                // (the billable coverage), not every billing
                // event: free-credit and over-cap events are
                // records too, but no charge follows them (spec
                // verifier finding 12).
                'charges' => (int) ($coverageCounts['billable'] ?? 0),
                'refunds' => 0,
                'clawbacks' => 0,
            ],
        ]);
    }

    /**
     * The suspension lever (spec Section 15, ADM-04): status
     * disabled stops enrollment and the QR landing (EC-06) while
     * already-pending redemptions still complete (RDM-28). The
     * lever is reversible, takes a reason, and is audit-logged
     * with the acting admin. It cannot touch a closed business
     * (closure is the owner's own terminal act) and no owner
     * action anywhere can suspend or reinstate.
     */
    public function suspend(Request $request, Business $business): RedirectResponse
    {
        $admin = $request->user('admin');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Enter a reason. It is saved in the audit log.',
        ]);

        if ($business->status !== 'active') {
            return back()->withErrors(['suspend' => 'Only an active business can be paused.']);
        }

        $business->forceFill(['status' => 'disabled'])->save();

        AuditLog::write('admin.business_suspended', $admin, ['reason' => $data['reason']], $business);

        return back()->with('status', 'Business paused.');
    }

    public function reinstate(Request $request, Business $business): RedirectResponse
    {
        $admin = $request->user('admin');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Enter a reason. It is saved in the audit log.',
        ]);

        if ($business->status !== 'disabled') {
            return back()->withErrors(['suspend' => 'Only a paused business can be reinstated.']);
        }

        $business->forceFill(['status' => 'active'])->save();

        AuditLog::write('admin.business_reinstated', $admin, ['reason' => $data['reason']], $business);

        return back()->with('status', 'Business reinstated.');
    }

    /**
     * The risk feed read model (spec Section 15): the referral
     * events that raised a flag, across all businesses, searchable
     * by phone, business, or rule.
     */
    public function riskFeed(Request $request): View
    {
        $admin = $request->user('admin');
        $search = trim((string) $request->query('q', ''));

        // Both demo legs (A-18 Finding 3): the risk feed is a
        // platform aggregate, so demo events count nowhere in
        // it, flag leg and referral-stamp leg alike.
        $events = \App\Support\DemoExclusion::referralEvents(ReferralEvent::query())
            ->where(function ($query) {
                $query->where('event_type', 'flag_raised')
                    ->orWhere('event_type', 'redemption_rejected')
                    ->orWhere('event_type', 'review_deny');
            })
            ->with(['business', 'referral.referrerMembership.customer', 'referral.referredCustomer'])
            ->when($search !== '', function ($query) use ($search) {
                $digits = preg_replace('/\D+/', '', $search);
                $query->where(function ($inner) use ($search, $digits) {
                    $inner->whereHas('business', fn ($b) => $b->whereRaw(Search::likeClause('name'), [Search::contains($search)]))
                        ->orWhereRaw(Search::likeClause('metadata'), [Search::contains($search)]);

                    if (strlen((string) $digits) >= 3) {
                        $inner->orWhereHas('referral.referredCustomer', fn ($c) => $c->where('phone_e164', 'like', '%'.$digits))
                            ->orWhereHas('referral.referrerMembership.customer', fn ($c) => $c->where('phone_e164', 'like', '%'.$digits));
                    }
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        AuditLog::write('admin.console_view', $admin, ['surface' => 'risk_feed', 'search' => $search]);

        return view('admin.risk-feed', [
            'events' => $events,
            'search' => $search,
        ]);
    }

    /**
     * Review-hold oversight (spec Section 15): every open hold
     * across businesses, read-only. Release and deny stay owner
     * acts in the owner's own queue; the console watches, it does
     * not decide for a tenant.
     */
    public function holds(Request $request): View
    {
        $admin = $request->user('admin');

        // Both demo legs (A-18 Finding 3), as on the home
        // surface's open-holds count above.
        $referrals = \App\Support\DemoExclusion::referrals(Referral::query())
            ->where('hold', true)
            ->with(['business', 'referrerMembership.customer', 'referredCustomer'])
            ->orderByDesc('held_at')
            ->get();

        $rewards = \App\Support\DemoExclusion::rewards(Reward::query())
            ->where('hold', true)
            ->with(['business', 'membership.customer', 'referral.referredCustomer'])
            ->orderByDesc('held_at')
            ->get();

        AuditLog::write('admin.console_view', $admin, ['surface' => 'holds']);

        return view('admin.holds', [
            'referrals' => $referrals,
            'rewards' => $rewards,
        ]);
    }

    public function audit(Request $request): View
    {
        $admin = $request->user('admin');
        $action = trim((string) $request->query('action', ''));

        $entries = AuditLog::query()
            ->with(['user', 'business'])
            ->when($action !== '', fn ($query) => $query->whereRaw(Search::likeClause('action'), [Search::contains($action)]))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        AuditLog::write('admin.console_view', $admin, ['surface' => 'audit_log', 'filter' => $action]);

        return view('admin.audit', [
            'entries' => $entries,
            'action' => $action,
        ]);
    }
}
