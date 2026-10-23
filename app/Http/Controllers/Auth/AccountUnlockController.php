<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\AccountUnlockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The emailed unlock link landing and its confirmation (spec Section
 * 8.3, Amendment 5). The link alone changes nothing: the landing page
 * asks for one explicit press, and that POST consumes the token
 * (single use), clears only the aggregate account lock, and sends the
 * owner to sign in with their password as usual. An invalid, reused,
 * or expired token gets one generic answer in every case.
 */
class AccountUnlockController extends Controller
{
    public function show(string $token): View
    {
        return view('auth.account-unlock', ['token' => $token]);
    }

    public function store(Request $request, AccountUnlockService $unlock): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $entry = $unlock->consume((string) $request->input('token'));

        if ($entry === null) {
            return back()->withErrors([
                'token' => 'This unlock link is invalid or has expired. If sign-in is still locked, use the link in the most recent unlock email we sent.',
            ]);
        }

        $unlock->clearAggregateLock($entry['identifier']);

        $user = User::find($entry['user_id']);

        AuditLog::write('owner.account_unlocked', null, [], $user?->business, $user);

        return redirect()->route('login')
            ->with('status', 'Your account has been unlocked. You can sign in now.');
    }
}
