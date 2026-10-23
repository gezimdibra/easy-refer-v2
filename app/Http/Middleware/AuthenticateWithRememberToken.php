<?php

namespace App\Http\Middleware;

use App\Services\Auth\RememberMeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persistent-login resolution for the selector/validator remember-me
 * (spec Section 8.4). Runs inside the web group after the session
 * starts: a guest presenting a valid remember cookie is signed in
 * (which regenerates the session id through the guard) and receives
 * the rotated cookie. Anything invalid is simply ignored, and the
 * normal auth guard decides the request.
 */
class AuthenticateWithRememberToken
{
    public function __construct(private RememberMeService $remember) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && $request->hasCookie(RememberMeService::COOKIE_NAME)) {
            $result = $this->remember->consume($request->cookie(RememberMeService::COOKIE_NAME));

            if ($result !== null) {
                Auth::login($result['user']);
                $request->session()->put('auth.login_at', now()->timestamp);
                $request->session()->put('auth.rotated_at', now()->timestamp);
                Cookie::queue($result['cookie']);
            }
        }

        return $next($request);
    }
}
