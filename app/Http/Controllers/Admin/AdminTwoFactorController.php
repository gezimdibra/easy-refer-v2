<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The console's two-factor surfaces (spec Sections 15 and 8.6):
 * mandatory enrollment and the per-session challenge. These are
 * the only surfaces an admin reaches before the
 * EnsureAdminTwoFactor middleware is satisfied, and enrollment
 * completion is itself the proof that satisfies it for the
 * session (the same rule the owner flow applies).
 */
class AdminTwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactorService $twoFactor): View|RedirectResponse
    {
        $admin = $request->user('admin');

        if ($admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.two-factor.challenge');
        }

        return view('admin.two-factor-setup', [
            'user' => $admin,
            'provisioningUri' => $admin->hasPendingTwoFactorEnrollment() ? $twoFactor->provisioningUri($admin) : null,
        ]);
    }

    public function start(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $admin = $request->user('admin');

        try {
            $twoFactor->startEnrollment($admin, (string) $request->input('current_password'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Enrollment failures are security events (spec
            // Section 8.8); the owner flow audits its own, the
            // admin flow was silent (security verifier F-07).
            AuditLog::write('admin.two_factor_enrollment_failed', $admin, ['surface' => 'start'], null, $admin);

            throw $e;
        }

        $request->session()->regenerate();

        AuditLog::write('admin.two_factor_enrollment_started', $request->user('admin'), [], null, $request->user('admin'));

        return redirect()->route('admin.two-factor.setup');
    }

    public function confirm(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $admin = $request->user('admin');

        try {
            $codes = $twoFactor->confirmEnrollment(
                $admin,
                (string) $request->input('code'),
                (string) $request->input('current_password')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            AuditLog::write('admin.two_factor_enrollment_failed', $admin, ['surface' => 'confirm'], null, $admin);

            throw $e;
        }

        $request->session()->put('admin_auth.two_factor_passed', true);
        $request->session()->regenerate();

        AuditLog::write('admin.two_factor_enabled', $request->user('admin'), [], null, $request->user('admin'));

        return redirect()->route('admin.home')
            ->with('two_factor_recovery_codes', $codes)
            ->with('status', 'Two-factor authentication is on. Save your recovery codes now; they are shown only this once.');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $admin = $request->user('admin');

        if (! $admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.two-factor.setup');
        }

        if ($request->session()->get('admin_auth.two_factor_passed') === true) {
            return redirect()->route('admin.home');
        }

        return view('admin.two-factor-challenge');
    }

    public function verifyChallenge(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $admin = $request->user('admin');
        $kind = $twoFactor->verifyChallenge($admin, (string) $request->input('code'));

        if ($kind === null) {
            AuditLog::write('admin.two_factor_challenge_failed', $admin, [], null, $admin);

            return back()->withErrors(['code' => 'That code did not work. Try again.']);
        }

        $request->session()->put('admin_auth.two_factor_passed', true);
        $request->session()->regenerate();

        AuditLog::write('admin.two_factor_challenge_passed', $admin, ['kind' => $kind], null, $admin);

        return redirect()->route('admin.home');
    }
}
