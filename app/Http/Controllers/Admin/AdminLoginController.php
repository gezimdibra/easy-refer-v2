<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Platform console sign-in (spec Section 15): a separate guard,
 * a separate session, and a strict account shape (role
 * platform_admin, no business). A valid owner credential is not
 * an admin credential and fails here with the same generic error
 * as a wrong password, so the endpoint never confirms which
 * accounts are admins. Failures are throttled per email and IP
 * and audited either way.
 */
class AdminLoginController extends Controller
{
    public function show(): View
    {
        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login|'.strtolower($data['email']).'|'.$request->ip();
        $max = (int) config('security.login_throttle.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            // A locked-out attempt is still an authentication
            // failure and is audited as one (spec Section 8.8;
            // security verifier F-08).
            AuditLog::write('admin.login_failed', null, [
                'email' => $data['email'],
                'ip_address' => $request->ip(),
                'reason' => 'throttled',
            ]);

            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Wait a minute and try again.',
            ]);
        }

        // Lookup by canonical email, like the owner sign-in, so
        // case and plus-addressing variants of the admin's own
        // address work (security verifier note N-02). The
        // attempt itself uses the stored address verbatim.
        $user = User::query()
            ->where('canonical_email', \App\Support\CanonicalEmail::of($data['email']))
            ->where('role', 'platform_admin')
            ->whereNull('business_id')
            ->first();

        if ($user === null || ! Auth::guard('admin')->attempt(['email' => $user->email, 'password' => $data['password']])) {
            RateLimiter::hit($key, (int) config('security.login_throttle.decay_seconds', 60));

            AuditLog::write('admin.login_failed', null, [
                'email' => $data['email'],
                'ip_address' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        AuditLog::write('admin.login', $user, ['ip_address' => $request->ip()]);

        return redirect()->route('admin.home');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');

        if ($admin !== null) {
            AuditLog::write('admin.logout', $admin, []);
        }

        Auth::guard('admin')->logout();
        $request->session()->forget('admin_auth.two_factor_passed');

        return redirect()->route('admin.login');
    }
}
