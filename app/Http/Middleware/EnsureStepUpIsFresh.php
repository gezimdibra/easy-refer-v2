<?php

namespace App\Http\Middleware;

use App\Services\Auth\StepUpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The step-up enforcement point (spec Section 8.7). Routes declared
 * step-up-protected carry this middleware; without a verification
 * inside the window, the request never reaches the action. The owner
 * is sent to the step-up challenge and, once verified, back to the
 * page they came from to submit the change again inside the window.
 * The return target is the page the protected form lives on (the
 * request's referer), kept only when it is one of this app's own
 * pages; anything else falls back to the security page (T3-F3: the
 * return used to be hardcoded to the security page, which stranded
 * owners mid-change on Billing and dropped their action silently).
 */
class EnsureStepUpIsFresh
{
    public function __construct(private StepUpService $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->stepUp->isFresh($request->session())) {
            return $next($request);
        }

        $request->session()->put('step_up.return', $this->returnTarget($request));

        return redirect()->route('security.step-up')
            ->with('status', 'For your security, confirm it is you before making that change.');
    }

    /**
     * The page the owner submitted the protected change from. Only
     * a same-host referer is honored (stored verbatim: the app
     * mounts under a base path, and a stored path would be
     * re-prefixed by the URL generator), and never the challenge
     * page itself (returning there would loop); a missing or
     * foreign referer falls back to the security page.
     */
    private function returnTarget(Request $request): string
    {
        $referer = $request->headers->get('referer');

        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);
            $path = is_array($parts) ? ($parts['path'] ?? null) : null;

            if (is_array($parts)
                && ($parts['host'] ?? null) === $request->getHost()
                && is_string($path)
                && str_starts_with($path, '/')
                && ! str_contains($path, '/security/step-up')) {
                return $referer;
            }
        }

        return route('security');
    }
}
