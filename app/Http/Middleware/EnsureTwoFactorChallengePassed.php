<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two-factor sign-in challenge gate (spec Section 8.6). A
 * session belonging to an owner with an active enrollment is not
 * fully signed in until the challenge is satisfied in that session;
 * until then every owner route except the challenge itself and
 * logout redirects to the challenge. Applies equally to password
 * sign-ins and remember-me restorations, because it guards the
 * routes, not the login path.
 */
class EnsureTwoFactorChallengePassed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && $user->hasTwoFactorEnabled()
            && ! $request->session()->get('auth.two_factor_passed')
            && ! $request->routeIs('two-factor.challenge', 'two-factor.challenge.store', 'logout')
        ) {
            return redirect()->guest(route('two-factor.challenge'));
        }

        return $next($request);
    }
}
