<?php

namespace App\Http\Controllers;

use App\Mail\NewsletterConfirmationMail;
use App\Models\Lead;
use App\Services\Security\RateBudgetService;
use App\Services\Security\TurnstileVerifier;
use App\Support\OpaqueToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Lead capture (spec Sections 2.1 item 8 and 2.7): the footer
 * newsletter (a real endpoint with a double opt-in confirmation
 * state, never a dead form) and the starter kit form (LEAD-01).
 * Both are rate-limited and Turnstile-protected when keys are
 * configured (spec Section 10.4, SW-26).
 */
class LeadController extends Controller
{
    public function __construct(
        private TurnstileVerifier $turnstile,
        private RateBudgetService $budgets,
    ) {}

    public function newsletter(Request $request): RedirectResponse
    {
        $data = $this->validateLead($request);

        $this->guard($request, 'newsletter');

        $token = OpaqueToken::generate();

        $lead = Lead::create([
            'kind' => 'newsletter',
            'email' => $data['email'],
            'source' => 'footer',
            'token_hash' => OpaqueToken::hash($token),
        ]);

        Mail::to($lead->email)->queue(new NewsletterConfirmationMail(
            route('newsletter.confirm', ['token' => $token]),
        ));

        // HOME-FTR-02's confirmation state, rendered by the footer.
        return back()->with(
            'newsletter_status',
            'Thanks. Please check your email to confirm your subscription.',
        );
    }

    public function confirmNewsletter(string $token): View
    {
        $lead = Lead::query()
            ->where('kind', 'newsletter')
            ->where('token_hash', OpaqueToken::hash($token))
            ->first();

        if ($lead !== null && $lead->confirmed_at === null) {
            $lead->forceFill(['confirmed_at' => now()])->save();
        }

        // The same page whether the token was fresh, already used,
        // or unknown: the response never enumerates subscribers.
        return view('marketing.lead-confirmed');
    }

    public function starterKit(Request $request): RedirectResponse
    {
        $data = $this->validateLead($request);

        $this->guard($request, 'starter_kit');

        Lead::create([
            'kind' => 'starter_kit',
            'email' => $data['email'],
            'source' => $request->input('source'),
        ]);

        // The follow-up sequence (LEAD-02 to LEAD-06) is gated on
        // the OD-4 ruling and the kit asset; the capture itself is
        // real today, and the success state is the design's own
        // wording for lead forms (design-system.md Section 6.4).
        return back()->with('lead_status', 'You are on the list');
    }

    /**
     * @return array{email: string}
     */
    private function validateLead(Request $request): array
    {
        return $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);
    }

    /**
     * The shared anti-abuse gate: Turnstile when configured, then
     * the per-IP database budget.
     */
    private function guard(Request $request, string $kind): void
    {
        if (! $this->turnstile->passes($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'email' => 'We could not confirm this submission. Please try again.',
            ]);
        }

        $count = $this->budgets->hit(RateBudgetService::keyFor(
            'lead_'.$kind,
            hash('sha256', (string) $request->ip()),
        ));

        if ($count > 10) {
            throw ValidationException::withMessages([
                'email' => 'Too many tries. Please wait a bit and try again.',
            ]);
        }
    }
}
