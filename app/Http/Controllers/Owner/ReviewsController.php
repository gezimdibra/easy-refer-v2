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

        $heldReferrals = Referral::query()
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->with(['referrerMembership.customer', 'referredCustomer'])
            ->oldest('held_at')
            ->get();

        $heldRewards = Reward::query()
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->with(['membership.customer', 'referral'])
            ->oldest('held_at')
            ->get();

        $unattributed = Referral::query()
            ->where('business_id', $business->id)
            ->where('attribution_state', 'unattributed')
            ->with(['referrerMembership.customer', 'referredCustomer'])
            ->latest('redeemed_at')
            ->limit(20)
            ->get();

        $rejections = ReferralEvent::query()
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

        return view('owner.reviews', [
            'business' => $business,
            'heldReferrals' => $heldReferrals,
            'heldRewards' => $heldRewards,
            'unattributed' => $unattributed,
            'rejections' => $rejections,
            'numberLinks' => $numberLinks,
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
            return back()->with('status', 'That referral has already been resolved and can no longer be denied.');
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
            return back()->with('status', 'That reward has already been resolved and can no longer be denied.');
        }

        AuditLog::write('review.reward_denied', $request->user(), [], null, $reward);

        return back()->with('status', 'Reward denied.');
    }

    private function authorizeBusiness(Request $request, int $businessId): void
    {
        abort_unless((int) $request->user()->business_id === $businessId, 404);
    }
}
