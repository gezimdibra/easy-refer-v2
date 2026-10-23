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
                ->withErrors(['account' => 'Your account is closed, so this cannot be changed. You can still view your history.']);
        }

        return $next($request);
    }
}
