<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Support\CanonicalEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Account email change (spec Section 8.5; matrix AUTH-30). The change
 * takes effect immediately and the new address starts unverified.
 * Verification proves control of the new address and is what makes
 * receipts and password resets reach it; it does NOT gate the QR
 * code or billing (flowIsActive reads business status and billing
 * status only, Train A2 / Amendment 37: the earlier copy claiming
 * a verification gate was false and has been reworded everywhere).
 * A notification goes to the old address in the same request, and
 * the action sits behind the Section 8.7 step-up (wired as
 * middleware on the route).
 */
class EmailChangeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'new_email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = $request->user();
        $newEmail = (string) $request->input('new_email');
        $canonical = CanonicalEmail::of($newEmail);

        if ($canonical === $user->canonical_email) {
            throw ValidationException::withMessages([
                'new_email' => 'That is already the email address on your account.',
            ]);
        }

        $taken = User::query()
            ->where('canonical_email', $canonical)
            ->whereKeyNot($user->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'new_email' => 'That email address is already in use by another account.',
            ]);
        }

        $oldEmail = $user->email;

        $user->forceFill([
            'email' => $newEmail,
            'canonical_email' => $canonical,
            'email_verified_at' => null,
        ])->save();

        // The business row mirrors the account email and its
        // verification state (spec Section 7.2), as at signup and at
        // verification.
        $user->business?->forceFill([
            'contact_email' => $newEmail,
            'email_verified_at' => null,
        ])->save();

        // The verification gate now protects the new address: a
        // signed link goes there, and VerifyEmailController completes
        // the change when it is used.
        $user->sendEmailVerificationNotification();

        Notification::route('mail', $oldEmail)
            ->notify(new EmailChangedNotification($newEmail));

        AuditLog::write('owner.email_change_requested', $user, [
            'new_email_domain' => Str::after($canonical, '@'),
        ], null, $user);

        $request->session()->regenerate();

        return back()->with('status', 'Your email address has been changed. Verify the new address with the link we just sent; a notice also went to your old address.');
    }
}
