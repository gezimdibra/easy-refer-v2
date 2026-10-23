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
        }

        return redirect()->intended(route('dashboard').'?verified=1');
    }
}
