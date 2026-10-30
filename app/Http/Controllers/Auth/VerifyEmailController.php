<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard').'?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));

            // The business row mirrors the owner verification state
            // (spec Section 7.2) so activation checks read one place.
            $user->business?->forceFill(['email_verified_at' => $user->email_verified_at])->save();

            AuditLog::write('owner.email_verified', $user);

            // E4 email_verified (spec Section 16), server-side.
            app(\App\Services\Attribution\AttributionService::class)
                ->record('E4', $user->business);

            // Post-verification routing: while onboarding is
            // open, the link returns into the flow's Verify step
            // (P4, OB-U2), which shows the done state and the way
            // on. After the flow, the BU-5 rule stands in its
            // activation-redesign form (census C-36): the detour
            // to the billing page fires only when the business
            // needs a card to keep its code on — its block
            // reason is the exhausted allowance. A cardless
            // owner whose code is live on its free visits owes
            // nothing and lands on the dashboard; verification
            // itself still gates nothing, and this is a routing
            // surface, not a gate. Both detours live only in
            // this branch, so every later visit, including a
            // repeat click on the link, lands on the dashboard
            // as before.
            if ($user->business !== null && $user->business->onboarding_completed_at === null) {
                return redirect()->intended(route('onboarding.step', 'verify'));
            }

            if ($user->business?->flowBlockReason() === 'allowance_exhausted') {
                return redirect()->intended(route('settings.billing'));
            }
        }

        return redirect()->intended(route('dashboard').'?verified=1');
    }
}
