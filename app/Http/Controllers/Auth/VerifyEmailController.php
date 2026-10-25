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

            // Post-verification routing (spec Sections 9.6 and
            // 4.1; billing UI build unit BU-5): an owner whose
            // business has not started billing routes once, at
            // this transition, to the billing page's setup state.
            // The detour lives only in this branch, so every
            // later visit, including a repeat click on the link,
            // lands on the dashboard as before.
            if ($user->business?->billing_status === 'none') {
                return redirect()->intended(route('settings.billing'));
            }
        }

        return redirect()->intended(route('dashboard').'?verified=1');
    }
}
