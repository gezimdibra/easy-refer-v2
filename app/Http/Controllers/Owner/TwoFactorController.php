<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Two-factor enrollment, confirmation, and disable (spec Section
 * 8.6), hosted on the security page (spec Section 4.4). All three
 * sit behind a fresh password check in the request itself;
 * confirmation additionally takes a code from the authenticator
 * app and returns the recovery codes exactly once, through the
 * session flash to the security page.
 */
class TwoFactorController extends Controller
{
    public function start(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        // This form's own error bag (T1-F2), shared by the disable
        // form below: both live in the two-factor panel of the
        // security page, apart from the change-password form.
        $request->validateWithBag('two_factor', [
            'current_password' => ['required', 'string'],
        ]);

        $twoFactor->startEnrollment($request->user(), (string) $request->input('current_password'), 'two_factor');

        // Privilege change: the session ID rotates (spec Section 8.4).
        $request->session()->regenerate();

        AuditLog::write('owner.two_factor_enrollment_started', $request->user(), [], null, $request->user());

        return redirect()->route('security.two-factor.confirm');
    }

    public function confirmForm(Request $request, TwoFactorService $twoFactor): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPendingTwoFactorEnrollment()) {
            return redirect()->route('security');
        }

        return view('owner.two-factor-confirm', [
            'user' => $user,
            'provisioningUri' => $twoFactor->provisioningUri($user),
        ]);
    }

    /**
     * AU-12 (R5 pack A9 pick B): the setup QR. The provisioning URI
     * is never printed on the page as text again; it lives only
     * inside this SVG, served per request to the signed-in owner
     * with a pending enrollment, exactly like the flow QR images.
     */
    public function qr(Request $request, TwoFactorService $twoFactor): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPendingTwoFactorEnrollment()) {
            return redirect()->route('security');
        }

        return response(\App\Support\QrImage::svg($twoFactor->provisioningUri($user), 232), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function confirm(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $codes = $twoFactor->confirmEnrollment(
            $request->user(),
            (string) $request->input('code'),
            (string) $request->input('current_password')
        );

        // The owner just proved possession of the second factor, so
        // this session counts as challenged; the ID still rotates at
        // the privilege change (spec Sections 8.4, 8.6).
        $request->session()->put('auth.two_factor_passed', true);
        $request->session()->regenerate();

        AuditLog::write('owner.two_factor_enabled', $request->user(), [], null, $request->user());

        return redirect()->route('security')
            ->with('two_factor_recovery_codes', $codes)
            ->with('status', 'Two-factor authentication is on. Save your recovery codes now; they are shown only this once.');
    }

    public function disable(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validateWithBag('two_factor', [
            'current_password' => ['required', 'string'],
        ]);

        $twoFactor->disable($request->user(), (string) $request->input('current_password'), 'two_factor');

        $request->session()->forget('auth.two_factor_passed');
        $request->session()->regenerate();

        AuditLog::write('owner.two_factor_disabled', $request->user(), [], null, $request->user());

        return back()->with('status', 'Two-factor authentication has been turned off.');
    }
}
