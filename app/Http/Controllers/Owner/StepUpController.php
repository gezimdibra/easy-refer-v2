<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Auth\StepUpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The step-up challenge (spec Section 8.7). The owner re-enters the
 * current password, or a TOTP code where 2FA is enrolled; success
 * marks the session fresh inside the step-up window and returns the
 * owner to the page the protected action lives on. Failures are
 * audit-logged as security events (spec Section 8.8).
 */
class StepUpController extends Controller
{
    public function show(Request $request): View
    {
        return view('owner.step-up', ['user' => $request->user()]);
    }

    public function store(Request $request, StepUpService $stepUp): RedirectResponse
    {
        $request->validate([
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        if (! $stepUp->verifyCredential($user, $request->input('password'), $request->input('code'))) {
            AuditLog::write('owner.step_up_failed', $user, [], null, $user);

            throw ValidationException::withMessages([
                'password' => 'That did not match. Try again.',
            ]);
        }

        $stepUp->markVerified($request->session());
        $request->session()->regenerate();

        AuditLog::write('owner.step_up_verified', $user, [], null, $user);

        $return = $request->session()->pull('step_up.return', route('security'));

        return redirect()->to($this->localReturn($request, $return))
            ->with('status', 'Verified. You can complete that change now.');
    }

    /**
     * The stored return target, re-validated at read time (T3-F3):
     * a local path, or an absolute URL on this host, is honored;
     * anything else (a foreign URL, a protocol-relative URL, a
     * non-string) falls back to the security page.
     */
    private function localReturn(Request $request, mixed $return): string
    {
        if (! is_string($return) || $return === '') {
            return route('security');
        }

        if (str_starts_with($return, '/') && ! str_starts_with($return, '//')) {
            return $return;
        }

        $host = parse_url($return, PHP_URL_HOST);

        return is_string($host) && $host === $request->getHost() ? $return : route('security');
    }
}
