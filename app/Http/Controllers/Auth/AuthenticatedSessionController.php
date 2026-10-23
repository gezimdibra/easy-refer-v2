<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Services\Auth\RememberMeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, RememberMeService $remember): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $request->session()->put('auth.login_at', now()->timestamp);
        $request->session()->put('auth.rotated_at', now()->timestamp);

        if ($request->boolean('remember')) {
            Cookie::queue($remember->issue($request->user()));
        }

        AuditLog::write('owner.login', $request->user());

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Logout is POST-only with CSRF (audit L1) and destroys the
     * server-side session record (spec Section 8.4). The remember
     * token this browser holds is revoked, not just forgotten.
     */
    public function destroy(Request $request, RememberMeService $remember): RedirectResponse
    {
        AuditLog::write('owner.logout', $request->user());

        $remember->revokeValue($request->cookie(RememberMeService::COOKIE_NAME));
        Cookie::queue(Cookie::forget(RememberMeService::COOKIE_NAME));

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
