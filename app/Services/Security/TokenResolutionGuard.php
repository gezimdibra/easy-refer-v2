<?php

namespace App\Services\Security;

use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;

/**
 * The shared failed-token-resolution budget (spec Sections 3, 6,
 * and 10.4): every presentation of a token that does not resolve
 * to a live action (unknown, malformed, revoked, voided, denied,
 * expired) is recorded as a redemption_rejected event and counts
 * against one per-IP hourly budget shared by the customer flow
 * and the staff screens. Past the budget the caller answers 429
 * with the same generic body: the status differs, the information
 * does not (RDM-17).
 */
class TokenResolutionGuard
{
    public function __construct(private RateBudgetService $budgets) {}

    /**
     * Record one failed resolution. Returns true when this IP has
     * now exceeded the hourly budget and the caller must answer
     * 429 (with the same generic body it would otherwise render).
     */
    public function recordFailure(
        ?Business $business,
        ?Referral $referral,
        ?Reward $reward,
        ?Membership $membership,
        string $reason,
        ?string $ipAddress,
    ): bool {
        $failures = $this->budgets->hit(
            RateBudgetService::keyFor('token_fail', hash('sha256', (string) $ipAddress)),
        );

        ReferralEvent::record('redemption_rejected', 'counter', $business, $referral, $reward, $membership, [
            'reason' => $reason,
        ], $ipAddress);

        return $failures > (int) config('referrals.token_resolution_budget_per_ip_hour');
    }
}
