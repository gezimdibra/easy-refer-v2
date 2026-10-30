<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Auth\RememberMeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Account security page (spec Section 4.4): active sessions, sign-out
 * controls, password change (PasswordController), and the two-factor
 * status. Session rows come from the database session store, the only
 * session driver production uses.
 */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        // Amendment 38 (Train A3-3, D10): session times render in
        // the business's local time, the frame every other owner
        // surface uses; the raw session timestamp is UTC and read
        // hours wrong in every state. No user timezone is stored,
        // so the business frame is the one consistent choice; a
        // business-less account falls back to the unconverted
        // value (localTime itself falls back to the app zone).
        $business = $user->business;

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($row) => [
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'device' => \App\Support\DeviceSummary::of($row->user_agent),
                'last_active' => $business !== null
                    ? $business->localTime(Carbon::createFromTimestamp($row->last_activity))
                    : Carbon::createFromTimestamp($row->last_activity),
                'is_current' => $row->id === $currentSessionId,
            ]);

        return view('owner.security', [
            'user' => $user,
            'sessions' => $sessions,
        ]);
    }

    /**
     * Sign out every other device: other session records are deleted
     * and every remember token is revoked, so persistent-login cookies
     * stop working everywhere (spec Section 8.4). This device's live
     * session is the one thing that survives.
     */
    public function signOutOthers(Request $request, RememberMeService $remember): RedirectResponse
    {
        $user = $request->user();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $remember->revokeAll($user);

        AuditLog::write('owner.other_sessions_revoked', $user);

        return back()->with('status', 'Other sessions have been signed out.');
    }

    /**
     * Sign out everywhere, including this device.
     */
    public function signOutEverywhere(Request $request, RememberMeService $remember): RedirectResponse
    {
        $user = $request->user();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $remember->revokeAll($user);
        $user->forceFill(['remember_token' => null])->save();

        AuditLog::write('owner.all_sessions_revoked', $user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('status', 'You have been signed out on all devices.');
    }
}
