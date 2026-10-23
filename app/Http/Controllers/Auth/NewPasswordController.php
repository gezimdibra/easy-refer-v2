<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Services\Auth\RememberMeService;
use App\Support\CanonicalEmail;
use App\Support\PasswordPolicy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Reset tokens are single-use with a 60-minute expiry (broker config,
     * spec Section 4.3). A successful reset invalidates every existing
     * session and remember token for the account and notifies the owner.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        // The broker keys tokens to the stored email string, while the
        // identity is the canonical email (spec Section 4.3, EC-18):
        // resolve the account canonically first so a plus-variant or
        // case-variant submission of the same identity still matches
        // the token that was issued for it.
        $account = User::query()
            ->where('canonical_email', CanonicalEmail::of((string) $request->input('email')))
            ->first();

        $credentials = [
            'email' => $account?->email ?? (string) $request->input('email'),
            'password' => (string) $request->input('password'),
            'password_confirmation' => (string) $request->input('password_confirmation'),
            'token' => (string) $request->input('token'),
        ];

        $status = Password::reset(
            $credentials,
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => $request->input('password'),
                    'remember_token' => null,
                ])->save();

                app(RememberMeService::class)->revokeAll($user);

                DB::table('sessions')->where('user_id', $user->id)->delete();

                $user->notify(new PasswordChangedNotification);

                event(new PasswordReset($user));

                AuditLog::write('owner.password_reset', $user, [], null, $user);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => trans($status)]);
    }
}
