<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform console's mandatory-2FA binding (spec Section 15
 * and Section 8.6, OD-9 option (a)): two_factor.admin_required is
 * true, and this middleware is what makes it binding rather than
 * advisory. An admin without confirmed 2FA reaches only the 2FA
 * setup surface; an admin with confirmed 2FA who has not passed
 * this session's challenge reaches only the challenge. No console
 * route sits outside this middleware, so there is no path to a
 * console surface that skips it. The session key is dedicated to
 * the admin guard, so an owner session's challenge state can
 * never satisfy it (and vice versa).
 */
class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin === null) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();

        if (! $admin->hasTwoFactorEnabled()) {
            if (str_starts_with($route, 'admin.two-factor.') || $route === 'admin.logout') {
                return $next($request);
            }

            return redirect()->route('admin.two-factor.setup');
        }

        if ($request->session()->get('admin_auth.two_factor_passed') !== true) {
            if (str_starts_with($route, 'admin.two-factor.challenge') || $route === 'admin.logout') {
                return $next($request);
            }

            return redirect()->route('admin.two-factor.challenge');
        }

        return $next($request);
    }
}
