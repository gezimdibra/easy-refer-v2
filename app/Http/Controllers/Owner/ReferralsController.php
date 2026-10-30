<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\SendFriendLinkSms;
use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\SmsMessage;
use App\Services\Billing\VisitValuation;
use App\Support\HoldReasons;
use App\Support\OpaqueToken;
use App\Support\ReferralFeeLine;
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

        // Demo double exclusion (A-18 Finding 3): business_id
        // scoping to the owner's own business is the flag leg
        // (the demo business has no owner account), and the
        // helper adds the source stamp leg on top.
        $base = \App\Support\DemoExclusion::referrals(Referral::query()->where('business_id', $business->id));

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
            ->with(['referrerMembership.customer', 'referredCustomer', 'reward', 'reward.business', 'billingEvent'])
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
            // RS2-1 (Amendment 44): one fee line per referral on
            // this page, from ONE settlement walk for the whole
            // request — never a walk per row.
            'feeLines' => $this->feeLines($business, $referrals->getCollection()->all()),
        ]);
    }

    public function show(Request $request, Referral $referral): View
    {
        $business = $request->user()->business;
        abort_unless($referral->business_id === $business->id, 404);

        $referral->applyExpiry();
        $referral->load(['referrerMembership.customer', 'referredCustomer', 'reward', 'billingEvent']);

        $events = ReferralEvent::query()
            ->where('referral_id', $referral->id)
            ->orderBy('created_at')
            ->get();

        return view('owner.referrals.show', [
            'business' => $business,
            'referral' => $referral,
            'events' => $events,
            // RS2-1 (Amendment 44): the detail fee line renders
            // for EVERY referral state (the list omits the
            // no-fee states; the detail carries the full truth).
            'feeLine' => $this->feeLines($business, [$referral])[$referral->id]['detail'],
            'holdReasons' => $referral->hold ? HoldReasons::sentencesForHold($referral->hold_rules ?? []) : [],
            // The current redeem link, shown to the owner so a
            // referral whose friend was never texted the link is
            // never stranded (REFL-08). Pending referrals only:
            // any other state has no live link to hand over.
            'redeemUrl' => $referral->state === Referral::STATE_PENDING
                ? route('refer.redeem.page', ['token' => $referral->redeem_token_encrypted])
                : null,
        ]);
    }

    /**
     * RS2-1 fee lines for a set of referrals (Amendment 44).
     * The settlement walk runs at most once per call, and only
     * when some referral actually carries a billing event; the
     * claiming records' states load in one query. Each line is
     * ReferralFeeLine's, keyed by referral id.
     *
     * @param  list<Referral>  $referrals
     * @return array<int, array{detail: string, list: ?string}>
     */
    private function feeLines(Business $business, array $referrals): array
    {
        $hasEvents = collect($referrals)->contains(
            fn (Referral $referral) => $referral->billingEvent !== null,
        );

        $settled = $hasEvents
            ? (new VisitValuation)->settle($business)['settled']
            : [];

        $recordIds = collect($referrals)
            ->map(fn (Referral $referral) => $referral->billingEvent?->billing_record_id)
            ->filter()
            ->unique()
            ->all();

        $recordStates = $recordIds === []
            ? []
            : BillingRecord::query()->whereIn('id', $recordIds)->pluck('state', 'id')->all();

        // TS3-01 (Sweep-03, Amendment 52): the void reason for
        // voided rows, in one query: closure's writer records
        // a referral_voided event with reason account_closed;
        // the denial writer records review_deny instead. Only
        // the closure sub-state takes the no-cause fee line.
        $voidedIds = collect($referrals)
            ->filter(fn (Referral $referral) => $referral->state === Referral::STATE_VOIDED)
            ->map(fn (Referral $referral) => $referral->id)
            ->all();

        $closureVoidedIds = $voidedIds === []
            ? []
            : ReferralEvent::query()
                ->whereIn('referral_id', $voidedIds)
                ->where('event_type', 'referral_voided')
                ->get()
                ->filter(fn (ReferralEvent $event) => ($event->metadata['reason'] ?? null) === 'account_closed')
                ->map(fn (ReferralEvent $event) => $event->referral_id)
                ->all();

        $lines = [];

        foreach ($referrals as $referral) {
            $event = $referral->billingEvent;

            $lines[$referral->id] = ReferralFeeLine::present(
                $referral,
                $event,
                $event !== null ? ($settled[$event->id] ?? null) : null,
                $event !== null && $event->billing_record_id !== null
                    ? ($recordStates[$event->billing_record_id] ?? null)
                    : null,
                $business,
                in_array($referral->id, $closureVoidedIds, true),
            );
        }

        return $lines;
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
