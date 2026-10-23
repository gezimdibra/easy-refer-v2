<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CanonicalEmail;
use App\Support\DummyHash;
use App\Support\OpaqueToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * The response is identical in text and timing class whether or not
     * the email belongs to an account (spec Sections 4.3 and 8.3): an
     * unknown email performs the same class of work (token generation
     * plus a full Argon2id verify) instead of returning early. Requests
     * carry a per-email budget on top of the route-level per-IP
     * throttle, so the endpoint cannot be used to flood a mailbox
     * (audit M11). The audit row is written only when a link was
     * actually sent, so the trail counts sends, not attempts.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = (string) $request->input('email');
        $budgetKey = 'password-reset:'.hash('sha256', CanonicalEmail::of($email));

        $user = User::query()->where('canonical_email', CanonicalEmail::of($email))->first();

        if ($user === null) {
            OpaqueToken::generate();
            password_verify('password-reset-timing', DummyHash::value());
        } elseif (! RateLimiter::tooManyAttempts($budgetKey, 5)) {
            RateLimiter::hit($budgetKey, 3600);

            $status = Password::sendResetLink(['email' => $user->email]);

            if ($status === Password::RESET_LINK_SENT) {
                AuditLog::write('owner.password_reset_requested', $user, [], null, $user);
            }
        }

        return back()->with('status', 'If an account exists for that email, a reset link is on its way.');
    }
}
