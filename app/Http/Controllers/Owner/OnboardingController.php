<?php

namespace App\Http\Controllers\Owner;

use App\Contracts\SquareCustomerGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\OnboardingLocationRequest;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\QrCode;
use App\Services\Billing\CardOnFileException;
use App\Services\Billing\CardOnFileService;
use App\Services\Billing\SquareOutcomeUnknown;
use App\Support\Onboarding;
use App\Support\StarterPacks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The onboarding front door (P4; onboarding design pack
 * Sections 4 and 5). A focused flow in the signup wizard's
 * grammar: Welcome, then the six working steps in the flow's
 * own order (Rewards, Card, Location, Verify, Poster, Try it;
 * benchmark ruling O-1, with the RS2-2 Location step between
 * Card and Verify), then the finish screen. State is derived from
 * SetupChecklist's predicates through App\Support\Onboarding,
 * never stored, so resume is simply re-entering: the flow
 * lands on the first open step from any device. The one stored
 * fact is businesses.onboarding_completed_at, stamped when
 * the owner passes the finish screen by either door.
 */
class OnboardingController extends Controller
{
    /** Placement -> the poster variant its print action opens. */
    private const PLACEMENT_POSTER = [
        'counter' => 'counter',
        'window' => 'window',
        'table' => 'tent',
        'receipt' => 'receipt',
    ];

    public function welcome(Request $request): View|RedirectResponse
    {
        if (($guard = $this->guard($request)) !== null) {
            return $guard;
        }

        $business = $request->user()->business;
        $states = Onboarding::states($business, $request->user());

        if (Onboarding::firstOpenStep($states) === null) {
            return redirect()->route('onboarding.finish');
        }

        return view('owner.onboarding.welcome', [
            'business' => $business,
            'states' => $states,
            'firstOpen' => Onboarding::firstOpenStep($states),
        ]);
    }

    public function step(Request $request, string $step): View|RedirectResponse
    {
        abort_unless(in_array($step, Onboarding::STEPS, true), 404);

        if (($guard = $this->guard($request)) !== null) {
            return $guard;
        }

        $user = $request->user();
        $business = $user->business;
        $states = Onboarding::states($business, $user);

        $data = [
            'business' => $business,
            'user' => $user,
            'states' => $states,
            'current' => $step,
            'done' => (bool) ($states[$step] ?? false),
            'next' => Onboarding::nextStep($step),
        ];

        if ($step === 'rewards') {
            $data['packs'] = StarterPacks::forType($business->business_type);
        }

        if ($step === 'card') {
            $data['plan'] = Plan::launch();
            $data['cardSaved'] = $request->session()->has('onboarding_card_saved');
            $data['cardFailed'] = $request->session()->has('onboarding_card_failed');
            // FLOW-STATE TRUTH train: the success panels may
            // claim "Your code is on" only from the gate itself
            // (flowIsActive), and name the remaining leg from
            // the gate's own predicates.
            $data['flowActive'] = $business->flowIsActive();
            $data['rewardsMissing'] = ! $business->hasFriendRewardText();
        }

        if ($step === 'poster') {
            $codes = QrCode::ensurePlacements($business);
            // The spot lines are the QR page's [MIX-04] lines
            // (Amendment 31, superseding the [MIX-03] lines):
            // one language for the same spots in both places.
            $lines = [
                'counter' => 'By the register, where people pay. Prints on half a sheet of paper (5.8 x 8.3 in).',
                'window' => 'In the front window, facing out. A full sheet of paper (8.3 x 11.7 in), so people can read it from the sidewalk.',
                'table' => 'Standing up on your tables. The sheet is 8.3 x 5.8 in and folds in half to stand.',
                'receipt' => 'Handed out with the receipt. A small card, 3.3 x 2.2 in, the size of a business card.',
            ];
            $data['placements'] = collect(QrCode::PLACEMENTS)->map(fn (string $placement) => [
                'key' => $placement,
                'variant' => self::PLACEMENT_POSTER[$placement],
                'code' => $codes[$placement],
                'line' => $lines[$placement],
            ])->all();
            $data['flowActive'] = $business->flowIsActive();
        }

        if ($step === 'test') {
            // Amendment 38 (Train A3-4, F-17): the test step
            // branches on the flow state like the poster step
            // does; without this the step waited forever in the
            // card-skipped and paused states.
            $data['flowActive'] = $business->flowIsActive();
            $qr = $business->activeQrCode();
            $data['imageUrl'] = route('owner.qr.image');
            $data['joinDisplay'] = preg_replace('#^https?://#', '', route('join.show', ['slug' => $business->ensureJoinSlug()]));
            $data['qr'] = $qr;
        }

        return view('owner.onboarding.'.$step, $data);
    }

    /**
     * OB-3's save (OB-U4): the same CardOnFileService the
     * billing page uses, behind the flow's own outcome mapping.
     * Success does NOT advance silently (benchmark O-4): it
     * returns to the card step carrying the success state, and
     * the owner continues from there. Failure returns with the
     * pack's failure state; no predicate flips on a failed save
     * (the audit's S6, pinned by name in OnboardingTest).
     */
    public function storeCard(Request $request, CardOnFileService $cards): RedirectResponse
    {
        if (($guard = $this->guard($request)) !== null) {
            return $guard;
        }

        $data = $request->validate([
            'source_id' => ['required', 'string', 'max:255'],
        ], [
            'source_id.required' => 'Some card details need another look.',
        ]);

        $business = $request->user()->business;

        try {
            $method = $cards->saveCard($business, $data['source_id'], $request->user());
        } catch (CardOnFileException) {
            return redirect()->route('onboarding.step', 'card')
                ->with('onboarding_card_failed', true)
                ->withErrors([
                    'card' => 'We could not set up billing for your business. Try again, and contact support@easy-refer.com if it keeps happening.',
                ]);
        } catch (SquareOutcomeUnknown) {
            return redirect()->route('onboarding.step', 'card')
                ->with('status', 'We are still confirming your card with Square. This page will update on its own. Please do not add the card again.');
        }

        if ($method->state !== PaymentMethod::STATE_SUCCEEDED) {
            return redirect()->route('onboarding.step', 'card')
                ->with('onboarding_card_failed', true)
                ->withErrors([
                    'card' => 'Your card was declined. No charge was made. Check the details or try a different card.',
                ]);
        }

        return redirect()->route('onboarding.step', 'card')
            ->with('onboarding_card_saved', true);
    }

    public function finish(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $business = $user->business;

        // A closed business never renders the flow (pack 5.5);
        // completion does not retire the finish screen itself,
        // which is how an owner who skipped the card returns.
        if ($business->isClosed() || $business->status === 'disabled') {
            return redirect()->route('dashboard');
        }

        $states = Onboarding::states($business, $user);

        return view('owner.onboarding.finish', [
            'business' => $business,
            'states' => $states,
            // RS2-3 (Amendment 44; wording Amendment 57): the
            // typical-spend value panel offers itself only
            // while the ticket is unset
            // (NULL or a calculator-carried 0); once a figure
            // exists the finish screen renders exactly as before.
            'showValuePanel' => (int) $business->average_ticket_cents === 0,
            // SK-6 (Amendment 67): the optional "how did you
            // hear" panel offers itself only while no source is
            // recorded, and never to a claimed business (its
            // claim_source is the authoritative channel; see
            // App\Support\SignupSource for the precedence rule).
            'showSourcePanel' => \App\Support\SignupSource::captureOffered($business),
            'sourceOptions' => \App\Support\SignupSource::OPTIONS,
            'live' => $business->flowIsActive(),
            // Train F2: the not-live branch splits on WHY — the
            // rewards step precedes the card step (the checklist
            // order), so a textless owner is sent to rewards
            // first even when both are missing (F2-FIN-01).
            'rewardsMissing' => ! $business->hasFriendRewardText(),
            // FLOW-STATE TRUTH train: the finish branches name
            // the remaining gates from the gate's own legs — a
            // both-gates-missing business must not be told the
            // reward alone turns the code on.
            // Two-key re-review Finding 2 (2026-10-30): the
            // card leg is split FOR RENDERING — billingAllowsFlow
            // lumps the no-card states with past_due-past-grace,
            // whose remedies differ (a saved card flips only
            // none/setup_incomplete; a paused past_due business
            // returns only via a successful charge), so the view
            // gets the two states as separate facts.
            'cardGateOpen' => $business->billingIsNoCard(),
            'pastDuePaused' => $business->billingIsPastDuePaused(),
            // Activation merge (2026-10-30): the finish also
            // reads the gate's own block reason for the
            // allowance-exhausted branch (ACT-FIN-03).
            'blockReason' => $business->flowBlockReason(),
            'testDone' => (bool) ($states['test'] ?? false),
            'emailVerified' => (bool) ($states['verify'] ?? false),
        ]);
    }

    /**
     * RS2-2: the Location step's save. The address that used to
     * be signup Step 4 is stored here, under the extracted
     * signup address rules (OnboardingLocationRequest), and the
     * save advances the flow in the same tap. Saving an address
     * also completes the deferred Square provisioning when no
     * Square customer exists yet (an owner who skipped the Card
     * step): the gateway now has the address the checkpoint
     * deferred for. When the Card step already provisioned the
     * customer, this save is address-only; provisioning failure
     * never fails the save (the card moment retries).
     */
    public function storeLocation(OnboardingLocationRequest $request, SquareCustomerGateway $square): RedirectResponse
    {
        if (($guard = $this->guard($request)) !== null) {
            return $guard;
        }

        $data = $request->validated();
        $business = $request->user()->business;

        $business->forceFill([
            'country' => $data['country'],
            'street' => $data['street'],
            'city' => $data['city'],
            'state' => $data['state'],
            'postal_code' => $data['postal_code'],
        ])->save();

        AuditLog::write('business.profile_updated', $request->user(), [], $business);

        if ($business->square_customer_id === null) {
            try {
                $squareCustomerId = $square->createCustomerFor($business);
            } catch (\Throwable) {
                $squareCustomerId = null;
            }

            if ($squareCustomerId !== null) {
                $business->forceFill(['square_customer_id' => $squareCustomerId])->save();
            }
        }

        return redirect()->route('onboarding.step', Onboarding::nextStep('location'));
    }

    /**
     * RS2-3 (Amendment 44; wording Amendment 57): the Finish
     * screen's optional typical-spend value save. It writes the existing nullable
     * businesses.average_ticket_cents through the Settings ->
     * Value discipline — the same bounds, the same messages,
     * the same audit action — with `nullable` in place of
     * `required`: a blank submit is a skip (a no-op redirect,
     * never an error), and skipping is otherwise just pressing
     * a door, so nothing here is ever forced. The figure is an
     * estimate input only; it touches no charge path.
     */
    public function storeValue(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        // The finish screen's own entry rules (see finish()):
        // a closed or disabled business never renders the flow.
        if ($business === null || $business->isClosed() || $business->status === 'disabled') {
            return redirect()->route('dashboard');
        }

        $data = $request->validate([
            'average_ticket' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'average_ticket.numeric' => 'Enter a dollar amount, like 45 or 45.50.',
            'average_ticket.min' => 'Enter an amount of 0 or more.',
            'average_ticket.max' => 'Enter an amount under $100,000.',
        ]);

        $raw = $data['average_ticket'] ?? null;

        if ($raw === null || $raw === '') {
            return redirect()->route('onboarding.finish');
        }

        $business->forceFill([
            'average_ticket_cents' => (int) round(((float) $raw) * 100),
        ])->save();

        AuditLog::write('business.average_ticket_updated', $request->user(), [], $business);

        return redirect()->route('onboarding.finish')->with('status', 'Value saved.');
    }

    /**
     * SK-6 (Amendment 67): the Finish screen's optional
     * "how did you hear about us" save, behind the same
     * discipline as storeValue above. The question is never
     * required: a blank source is a skip (a no-op redirect,
     * never an error), and skipping is otherwise just pressing
     * a door. The answer is stored ONCE at the business level
     * with its capture timestamp; a later submit never
     * overwrites the first answer.
     *
     * PRECEDENCE: a claimed business's claim_source is the
     * authoritative channel (App\Support\SignupSource). The
     * panel is never offered to one, and this save is a no-op
     * for one, so the self-reported field can never overwrite
     * or contradict the claim record.
     */
    public function storeSource(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        // The finish screen's own entry rules (see finish()):
        // a closed or disabled business never renders the flow.
        if ($business === null || $business->isClosed() || $business->status === 'disabled') {
            return redirect()->route('dashboard');
        }

        $data = $request->validate([
            'signup_source' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Support\SignupSource::keys())],
            'signup_source_detail' => ['nullable', 'string', 'max:120'],
        ], [
            'signup_source.in' => 'Pick one of the choices, or leave it blank to skip.',
            'signup_source_detail.max' => 'Keep the street or area under 120 characters.',
        ]);

        $source = $data['signup_source'] ?? null;

        if ($source === null || $source === ''
            || ! \App\Support\SignupSource::captureOffered($business)) {
            return redirect()->route('onboarding.finish');
        }

        $detail = trim((string) ($data['signup_source_detail'] ?? ''));

        $business->forceFill([
            'signup_source' => $source,
            'signup_source_detail' => $detail === '' ? null : $detail,
            'signup_source_captured_at' => now(),
        ])->save();

        AuditLog::write('business.signup_source_recorded', $request->user(), ['source' => $source], $business);

        return redirect()->route('onboarding.finish')->with('status', 'Thanks, saved.');
    }

    /**
     * Passing the finish screen, by either door, records
     * completion once (pack 5.2). The predicates keep governing
     * every surface afterward; a card removed a month later
     * reopens the checklist without resurrecting the flow.
     */
    public function complete(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'door' => ['required', 'in:dashboard,billing,rewards'],
        ]);

        $business = $request->user()->business;

        if ($business->onboarding_completed_at === null) {
            $business->forceFill(['onboarding_completed_at' => now()])->save();
        }

        return match ($data['door']) {
            'billing' => redirect()->to(route('settings.billing').'#card'),
            'rewards' => redirect()->route('settings.rewards'),
            default => redirect()->route('dashboard'),
        };
    }

    /**
     * The entry rules shared by the flow's screens (pack 5.2
     * and 5.5): a closed or paused business never renders the
     * flow, and once the finish screen has been passed the
     * flow is retired, so re-entry lands on the dashboard.
     */
    private function guard(Request $request): ?RedirectResponse
    {
        $business = $request->user()->business;

        if ($business === null || $business->isClosed() || $business->status === 'disabled') {
            return redirect()->route('dashboard');
        }

        if (Onboarding::hasCompleted($business)) {
            return redirect()->route('dashboard');
        }

        return null;
    }
}
