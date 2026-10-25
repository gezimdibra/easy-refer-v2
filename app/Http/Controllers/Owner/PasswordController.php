<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Notifications\PasswordChangedNotification;
use App\Services\Auth\RememberMeService;
use App\Services\Auth\StepUpService;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Password change from the security page (spec Section 4.4). Verifies
 * the current password with the algorithm-agnostic password_verify
 * (the same pattern as the login path, so a stored hash from older
 * parameters can never turn this endpoint into a 500), enforces the
 * Section 8.2 policy, regenerates the session ID at the privilege
 * change, invalidates every other session, revokes every remember
 * token, and notifies the owner.
 */
class PasswordController extends Controller
{
    public function update(Request $request, RememberMeService $remember, StepUpService $stepUp): RedirectResponse
    {
        // Errors go to this form's own bag (T1-F2): the security
        // page hosts several forms with a current_password field,
        // and the shared default bag printed this form's errors
        // under every one of them.
        $request->validateWithBag('password', [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $user = $request->user();

        // The current-password re-entry this action collects is its
        // step-up verification (spec Section 8.7); it runs through
        // StepUpService, the single implementation of the rule.
        if (! $stepUp->verifyPassword($user, (string) $request->input('current_password'))) {
            throw ValidationException::withMessages([
                'current_password' => trans('auth.password'),
            ])->errorBag('password');
        }

        if (password_verify((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The new password must be different from your current password.',
            ])->errorBag('password');
        }

        $user->forceFill([
            'password' => $request->input('password'),
            'remember_token' => null,
        ])->save();

        $remember->revokeAll($user);

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $request->session()->regenerate();

        // A completed password change leaves the step-up window
        // fresh: the owner has just proven the credential.
        $stepUp->markVerified($request->session());

        $user->notify(new PasswordChangedNotification);

        AuditLog::write('owner.password_changed', $user, [], null, $user);

        return back()->with('status', 'Password updated.');
    }
}
