<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Referral\RedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The owner's review queue (spec Sections 3.1 and 5.6): every held
 * referral and held reward with its reason in plain sentences,
 * plus the unattributed redemptions and rejected presentations the
 * counter produced. Release and deny are owner actions written to
 * the audit log; deny is terminal, and a denied referral never
 * bills and never mints (RDM-22).
 */
class ReviewsController extends Controller
{
    public function __construct(private RedemptionService $redemption) {}

    public function index(Request $request): View
    {
        $business = $request->user()->business;

        // Demo double exclusion (A-18 Finding 3): owner
        // scoping is the flag leg; the helpers add the stamp
        // leg to every read on this page.
        $heldReferrals = \App\Support\DemoExclusion::referrals(Referral::query())
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->with(['referrerMembership.customer', 'referredCustomer'])
            ->oldest('held_at')
            ->get();

        $heldRewards = \App\Support\DemoExclusion::rewards(Reward::query())
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->with(['membership.customer', 'referral'])
            ->oldest('held_at')
            ->get();

        $unattributed = \App\Support\DemoExclusion::referrals(Referral::query())
            ->where('business_id', $business->id)
            ->where('attribution_state', 'unattributed')
            ->with(['referrerMembership.customer', 'referredCustomer'])
            ->latest('redeemed_at')
            ->limit(20)
            ->get();

        $rejections = \App\Support\DemoExclusion::referralEvents(ReferralEvent::query())
            ->where('business_id', $business->id)
            ->where('event_type', 'redemption_rejected')
            ->latest('id')
            ->limit(20)
            ->get();

        // Pending number links inside the cooling window
        // (REF-54): informational; claims on these accounts hold
        // automatically until the window passes.
        $coolingDays = (int) config('referrals.number_link_cooling_days');
        $numberLinks = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'number_linked')
            ->where('created_at', '>=', now()->subDays($coolingDays))
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(function (ReferralEvent $event) {
                $customerId = (int) ($event->metadata['customer_id'] ?? 0);
                $event->setAttribute('customer_name', $customerId > 0
                    ? \App\Models\Customer::query()->whereKey($customerId)->value('first_name')
                    : null);

                return $event;
            });

        // R2 REV-Q (design section 5): ONE queue, oldest first
        // across both types, so the stalest work is first on a
        // page whose only job is clearing it. Presentation only:
        // the held sets, counts, and release/deny semantics are
        // exactly the queries above.
        $queue = collect()
            ->merge($heldReferrals->map(fn ($item) => ['type' => 'referral', 'item' => $item, 'held_at' => $item->held_at]))
            ->merge($heldRewards->map(fn ($item) => ['type' => 'reward', 'item' => $item, 'held_at' => $item->held_at]))
            ->sortBy(fn ($entry) => $entry['held_at']?->timestamp ?? PHP_INT_MAX)
            ->values();

        $oldest = $queue->first()['held_at'] ?? null;

        return view('owner.reviews', [
            'business' => $business,
            'heldReferrals' => $heldReferrals,
            'heldRewards' => $heldRewards,
            'queue' => $queue,
            // Amendment 32 [PM-REV-3]/[PM-REV-4]: the header
            // renders the oldest age through AgeLabel (the row
            // pills' one vocabulary) and shows the warning-pill
            // legend only while the oldest item is in the
            // warning state, which is exactly when any item is.
            // held_at is nullable: with no recorded age the
            // header states no age at all (the retired code
            // claimed "oldest 0 days" for that state too).
            'oldestAge' => $oldest !== null ? \App\Support\AgeLabel::of($oldest) : null,
            'hasWarningAge' => $oldest !== null && (int) $oldest->diffInDays(now()) >= 7,
            'unattributed' => $unattributed,
            'rejections' => $rejections,
            'numberLinks' => $numberLinks,
            'numberLinkCoolingDays' => $coolingDays,
        ]);
    }

    public function releaseReferral(Request $request, Referral $referral): RedirectResponse
    {
        $this->authorizeBusiness($request, $referral->business_id);

        $this->redemption->releaseReferral($referral);
        AuditLog::write('review.referral_released', $request->user(), [], null, $referral);

        return back()->with('status', 'Referral released.');
    }

    public function denyReferral(Request $request, Referral $referral): RedirectResponse
    {
        $this->authorizeBusiness($request, $referral->business_id);

        if (! $this->redemption->denyReferral($referral)) {
            return back()->with('status', $this->denyRefusal($referral->fresh(), 'referral'));
        }

        AuditLog::write('review.referral_denied', $request->user(), [], null, $referral);

        return back()->with('status', 'Referral denied. It will not bill and no reward will be paid.');
    }

    public function releaseReward(Request $request, Reward $reward): RedirectResponse
    {
        $this->authorizeBusiness($request, $reward->business_id);

        $this->redemption->releaseReward($reward);
        AuditLog::write('review.reward_released', $request->user(), [], null, $reward);

        return back()->with('status', 'Reward released.');
    }

    public function denyReward(Request $request, Reward $reward): RedirectResponse
    {
        $this->authorizeBusiness($request, $reward->business_id);

        if (! $this->redemption->denyReward($reward)) {
            return back()->with('status', $this->denyRefusal($reward->fresh(), 'reward'));
        }

        AuditLog::write('review.reward_denied', $request->user(), [], null, $reward);

        return back()->with('status', 'Reward denied. It will not be paid.');
    }

    /**
     * BREAKER D-2: a refused deny names the item's actual state,
     * so the owner is never told a generic "already resolved"
     * about an item that was, say, never held at all. Every branch
     * below is true of the row it describes (state-truth rule).
     */
    private function denyRefusal(Referral|Reward|null $item, string $noun): string
    {
        if ($item === null) {
            return "That {$noun} can no longer be denied.";
        }

        $stateLabel = match ($item->state) {
            Referral::STATE_PENDING => 'waiting for its visit',
            Reward::STATE_PENDING_VESTING => 'still waiting to become available',
            Reward::STATE_AVAILABLE => 'available to claim',
            Referral::STATE_REDEEMED => 'already redeemed',
            Reward::STATE_CLAIMED => 'already claimed',
            Referral::STATE_EXPIRED, Reward::STATE_EXPIRED => 'expired',
            Referral::STATE_VOIDED, Reward::STATE_DENIED => 'already denied',
            default => $item->state,
        };

        if (in_array($item->state, [Referral::STATE_VOIDED, Reward::STATE_DENIED], true)) {
            return "That {$noun} was already denied. A denied {$noun} can no longer be changed.";
        }

        if (! $item->hold && $item->hold_resolved_at !== null) {
            return "That {$noun} was already released. A released {$noun} can no longer be denied.";
        }

        if ($item->hold) {
            return "That {$noun} can no longer be denied: it is {$stateLabel}.";
        }

        return "That {$noun} can no longer be denied: it is {$stateLabel} and was never held for review.";
    }

    private function authorizeBusiness(Request $request, int $businessId): void
    {
        abort_unless((int) $request->user()->business_id === $businessId, 404);
    }
}
