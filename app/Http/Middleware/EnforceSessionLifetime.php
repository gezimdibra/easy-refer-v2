<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session lifetime rules (spec Section 8.4), applied to the whole
 * authenticated group: an absolute cap on total session age from the
 * login timestamp, plus periodic session-ID rotation during activity
 * so a captured ID has a short useful life. The idle timeout is the
 * framework session lifetime. Every authenticated session is created
 * by a flow that stamps the login time (password login, registration,
 * remember-me consumption), so the cap binds persistent sessions too.
 */
class EnforceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            $session = $request->session();
            $now = now()->timestamp;
            $loginAt = $session->get('auth.login_at');

            if ($loginAt === null) {
                $session->put('auth.login_at', $now);
                $session->put('auth.rotated_at', $now);
            } else {
                if ($now - (int) $loginAt > (int) config('security.session.absolute_lifetime_minutes') * 60) {
                    Auth::logout();
                    $session->invalidate();
                    $session->regenerateToken();

                    return redirect()->route('login')
                        ->with('status', 'Your session expired. Please sign in again.');
                }

                $rotatedAt = $session->get('auth.rotated_at');

                if ($rotatedAt === null) {
                    $session->put('auth.rotated_at', (int) $loginAt);
                } elseif ($now - (int) $rotatedAt > (int) config('security.session.rotation_minutes') * 60) {
                    $session->regenerate();
                    $session->put('auth.rotated_at', $now);
                }
            }
        }

        return $next($request);
    }
}
