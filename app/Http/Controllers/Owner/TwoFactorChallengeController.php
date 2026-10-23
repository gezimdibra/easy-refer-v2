<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The two-factor sign-in challenge (spec Section 8.6). Reached only
 * while EnsureTwoFactorChallengePassed holds the session short of
 * full sign-in. Accepts a current authenticator code or one unused
 * recovery code; success marks the session as challenged and rotates
 * its ID, and the owner continues to the page they asked for.
 */
class TwoFactorChallengeController extends Controller
{
    public function show(): View
    {
        return view('owner.two-factor-challenge');
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $method = $twoFactor->verifyChallenge($user, (string) $request->input('code'));

        if ($method === null) {
            AuditLog::write('owner.two_factor_challenge_failed', $user, [], null, $user);

            throw ValidationException::withMessages([
                'code' => 'That code did not match. Try the current code from your authenticator app, or one of your recovery codes.',
            ]);
        }

        $request->session()->put('auth.two_factor_passed', true);
        $request->session()->regenerate();

        AuditLog::write('owner.two_factor_challenge_passed', $user, ['method' => $method], null, $user);

        return redirect()->intended(route('dashboard'));
    }
}
