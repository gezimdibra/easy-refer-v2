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
    public function show(Request $request): View
    {
        // AU-9: the recovery mode is a server-rendered mode of the
        // same screen (a presentation toggle that works without
        // JS); both modes post the one 'code' field.
        return view('owner.two-factor-challenge', [
            'mode' => $request->query('mode') === 'recovery' ? 'recovery' : 'code',
        ]);
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        // AU-9: without JS the six boxes arrive as code_1..code_6
        // and are assembled here, exactly as the flow verify step
        // assembles its boxes; with JS the composed 'code' field
        // carries the same value.
        if (! $request->filled('code')) {
            $assembled = collect(range(1, 6))
                ->map(fn (int $i) => (string) $request->input('code_'.$i, ''))
                ->implode('');
            if ($assembled !== '') {
                $request->merge(['code' => $assembled]);
            }
        }

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
