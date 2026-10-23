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
 */
class EnsureStepUpIsFresh
{
    public function __construct(private StepUpService $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->stepUp->isFresh($request->session())) {
            return $next($request);
        }

        $request->session()->put('step_up.return', route('security'));

        return redirect()->route('security.step-up')
            ->with('status', 'For your security, confirm it is you before making that change.');
    }
}
