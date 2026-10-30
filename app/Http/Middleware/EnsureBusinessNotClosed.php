<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The closed-business wind-down (spec Section 5.5): after closure
 * the owner keeps a read-only login for 30 days (confirm claims on
 * the staff side, view history), so every owner-area mutation is
 * refused while reads keep working. Signing out and the closure
 * endpoint itself stay reachable. Applied to the owner route group
 * alongside auth; it never applies to the staff or customer flows,
 * whose claim completions are governed by the redemption service
 * (minted rewards stay claimable through the wind-down).
 *
 * Train A2 (F-06, ruling C-1): review resolution is the owner-side
 * half of that same wind-down. A reward or referral held at
 * closure (or held by a wind-down claim) must stay resolvable for
 * the whole window the close screen promises, so the four Review
 * release/deny routes are writable when closed. They finish
 * decisions the open business already owed; each is state-guarded
 * and audited in the redemption service, and a release after day
 * 30 is harmless (the reward is closure-expired on its next read).
 */
class EnsureBusinessNotClosed
{
    /**
     * Route names that remain writable on a closed business.
     * Alongside signing out and the closure endpoint itself, the
     * authentication-completion posts stay reachable: without
     * them an owner with two-factor sign-in could never finish
     * signing in, and the whole read-only wind-down would be
     * locked behind the challenge it can never submit (security
     * verifier F-02). Neither completes a business mutation;
     * both only finish proving who the owner is.
     *
     * @var list<string>
     */
    private const WRITABLE_WHEN_CLOSED = [
        'logout',
        'settings.account.close',
        'two-factor.challenge.store',
        'verification.send',
        'reviews.referrals.release',
        'reviews.referrals.deny',
        'reviews.rewards.release',
        'reviews.rewards.deny',
        // FE-CANCEL-01 (GAP 3): card REPLACE joins the list so
        // an ended owner with a balance can put a working card
        // on the account. The balance condition itself is
        // enforced in BillingCardController (the middleware
        // cannot express it); card REMOVE never joins, so a
        // card stays trapped on file while money is owed.
        'settings.billing.card.replace',
        // GAP 3 extension (build deviation, recorded): card
        // STORE joins under the same owed gate, for the ended
        // owner who never had a card (allowance-era balance).
        // Without it the E-10 line "you can update your card
        // any time in Settings, under Billing" would be false
        // for exactly the no-card balance case.
        'settings.billing.card.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $business = $user?->business;

        if ($business !== null
            && $business->isClosed()
            && ! $request->isMethodSafe()
            && ! in_array((string) $request->route()?->getName(), self::WRITABLE_WHEN_CLOSED, true)
        ) {
            return redirect()
                ->route('dashboard')
                ->withErrors(['account' => 'Your program has ended, so this cannot be changed. You can still view your history.']);
        }

        return $next($request);
    }
}
