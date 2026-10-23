<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\SendFriendLinkSms;
use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\SmsMessage;
use App\Support\HoldReasons;
use App\Support\OpaqueToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Referrals surface (spec Section 5.3): this business's
 * referrals under the state filters, a detail view whose timeline
 * is the referral_events ledger, and the regenerate-redeem-link
 * action (the old token dies the moment the new one is written;
 * the referral keeps its state; the regeneration is both a
 * timeline event and an audit row, and it is not a Deny).
 */
class ReferralsController extends Controller
{
    /**
     * Filter keys mapped to their query shape. A hold is a flag
     * orthogonal to state, so Held is its own filter.
     *
     * @var array<string, string>
     */
    private const FILTERS = [
        'all' => 'All',
        'pending' => 'Pending redemption',
        'redeemed' => 'Redeemed',
        'expired' => 'Expired',
        'held' => 'Held',
        'voided' => 'Voided',
    ];

    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS)
            ? (string) $request->query('filter')
            : 'all';

        // Expiry is applied at every read (spec Section 7.2), so
        // the counts and filters below see post-transition states,
        // not whatever the last sweeper left behind.
        Referral::applyExpiryForBusiness($business);

        $base = Referral::query()->where('business_id', $business->id);

        $counts = [
            'all' => (clone $base)->count(),
            'pending' => (clone $base)->where('state', Referral::STATE_PENDING)->count(),
            'redeemed' => (clone $base)->where('state', Referral::STATE_REDEEMED)->count(),
            'expired' => (clone $base)->where('state', Referral::STATE_EXPIRED)->count(),
            'held' => (clone $base)->where('hold', true)->count(),
            'voided' => (clone $base)->where('state', Referral::STATE_VOIDED)->count(),
        ];

        $stateForFilter = [
            'pending' => Referral::STATE_PENDING,
            'redeemed' => Referral::STATE_REDEEMED,
            'expired' => Referral::STATE_EXPIRED,
            'voided' => Referral::STATE_VOIDED,
        ];

        $referrals = $base
            ->with(['referrerMembership.customer', 'referredCustomer', 'reward'])
            ->when($filter === 'held', fn ($query) => $query->where('hold', true))
            ->when(isset($stateForFilter[$filter]), fn ($query) => $query->where('state', $stateForFilter[$filter]))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        // Expiry is applied on read (spec Section 7.2); refresh
        // the page's models so a just-expired row reads Expired.
        $referrals->getCollection()->each(function (Referral $referral) {
            if ($referral->applyExpiry()) {
                $referral->refresh();
            }
        });

        return view('owner.referrals.index', [
            'business' => $business,
            'referrals' => $referrals,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, Referral $referral): View
    {
        $business = $request->user()->business;
        abort_unless($referral->business_id === $business->id, 404);

        $referral->applyExpiry();
        $referral->load(['referrerMembership.customer', 'referredCustomer', 'reward']);

        $events = ReferralEvent::query()
            ->where('referral_id', $referral->id)
            ->orderBy('created_at')
            ->get();

        return view('owner.referrals.show', [
            'business' => $business,
            'referral' => $referral,
            'events' => $events,
            'holdReasons' => $referral->hold ? HoldReasons::sentences($referral->hold_rules ?? []) : [],
            // The current redeem link, shown to the owner so a
            // referral whose friend was never texted the link is
            // never stranded (REFL-08). Pending referrals only:
            // any other state has no live link to hand over.
            'redeemUrl' => $referral->state === Referral::STATE_PENDING
                ? route('refer.redeem.page', ['token' => $referral->redeem_token_encrypted])
                : null,
        ]);
    }

    public function regenerate(Request $request, Referral $referral): RedirectResponse
    {
        $business = $request->user()->business;
        abort_unless($referral->business_id === $business->id, 404);

        $referral->applyExpiry();

        if ($referral->state !== Referral::STATE_PENDING) {
            return back()->withErrors(['referral' => 'Only a referral still waiting for the visit can get a new link.']);
        }

        $plainToken = OpaqueToken::generate();

        $referral->forceFill([
            'redeem_token_hash' => OpaqueToken::hash($plainToken),
            'redeem_token_encrypted' => $plainToken,
        ])->save();

        ReferralEvent::record(
            'redeem_token_regenerated',
            'owner',
            $business,
            $referral,
            null,
            $referral->referrerMembership,
        );

        AuditLog::write('referral.redeem_link_regenerated', $request->user(), [], $business, $referral);

        // The friend only gets the new link by text if the
        // original link went by text (their claim-time consent is
        // what put it there); the message ledger is the record.
        $friendWasTexted = SmsMessage::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $referral->referred_customer_id)
            ->where('purpose', 'friend_link')
            ->exists();

        if ($friendWasTexted) {
            SendFriendLinkSms::dispatch($referral->id);

            return back()->with('status', 'New redeem link sent. The old link no longer works.');
        }

        // No text ever carried the link (the common, consent-gated
        // path): claiming a text was sent would strand the
        // referral, because the new token would exist nowhere the
        // owner can reach. The detail page shows the current link
        // for exactly this handover (REFL-07/REFL-08).
        return back()->with('status', 'New redeem link created. The old link no longer works. Share the new link with your customer from this page.');
    }
}
