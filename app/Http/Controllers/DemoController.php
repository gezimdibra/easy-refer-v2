<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\DemoAttribution;
use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\Referral;
use App\Services\Demo\DemoService;
use App\Services\Demo\DemoSessionManager;
use App\Services\Demo\DemoVerificationGateway;
use App\Services\Referral\NonceService;
use App\Services\Referral\RedemptionService;
use App\Support\OpaqueToken;
use App\Support\PhoneNumber;
use App\Support\QrImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie as CookieFacade;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The G6 demo Phase 1 surface (Amendment 63; design pass
 * Sections 2 to 12). The prospect plays a first-time customer
 * of Juniper Café through the claim side of the real loop:
 * claim landing, the real claim form's shape, verification
 * with the code shown on screen (the demo gateway; zero real
 * SMS), the reward token screen, the counter moment performed
 * on the real redemption machinery inside the demo guard, and
 * the owner-side coda.
 *
 * State lives in the server-side demo session (15-minute
 * fixed expiry). The typed phone number exists only in that
 * store. A demo session can never open or redeem a real
 * business's token: every token resolution here re-checks the
 * token's own business flag and the session's own claim.
 */
class DemoController extends Controller
{
    /** Trade chips (Section 7.4) to signup business types. */
    private const TRADE_TYPES = [
        'barber' => 'barber',
        'cafe' => 'cafe',
        'salon' => 'salon',
        'pizza' => 'other',
        'other' => 'other',
    ];

    public function __construct(
        private DemoSessionManager $sessions,
        private DemoService $demo,
        private DemoVerificationGateway $gateway,
        private NonceService $nonces,
        private RedemptionService $redemption,
    ) {}

    /**
     * The frame surface (S0): the start card, rendered on page
     * load WITHOUT creating a session or firing an event (a
     * landing view is not a demo start). A live session resumes
     * at its current step; the resting state replaces the card.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if ($this->demo->resting()) {
            // The resting screen carries the DEMO device like
            // every other demo screen (A-18 Finding 7), so it
            // needs the business for the banner and name chip.
            return view('demo.resting', ['business' => $this->business()]);
        }

        $session = $this->sessions->fromRequest($request);

        if ($session !== null) {
            return redirect()->to($this->stepUrl($session));
        }

        return view('demo.start', [
            'business' => $this->business(),
            'refusal' => session('demo_refusal'),
        ]);
    }

    /**
     * Start (or restart) a demo session. A restart invalidates
     * the prior session and tears its rows down first (Section
     * 11.1): the old token dies with the old session.
     */
    public function start(Request $request): RedirectResponse|View
    {
        $business = $this->business();

        if ($this->demo->resting()) {
            return redirect()->route('demo.show');
        }

        if ($this->demo->ipGuardState((string) $request->ip()) !== 'ok') {
            // The refusal answers the start attempt itself,
            // with the honest line on the start card (Section
            // 12). A live session from an earlier start is
            // untouched; only the NEW start is refused.
            return view('demo.start', [
                'business' => $business,
                'refusal' => 'Too many demo starts from this connection. Try again in a little while.',
            ]);
        }

        $prior = $this->sessions->fromRequest($request);

        if ($prior !== null) {
            $this->demo->teardownSessionRows($prior);
            $this->sessions->destroy($prior);
        }

        $entryPoint = in_array($request->input('entry_point'), ['hero', 'block'], true)
            ? (string) $request->input('entry_point')
            : 'block';

        [$session, $token] = $this->sessions->start([
            'session_key' => $this->demo->newSessionKey(),
            'entry_point' => $entryPoint,
            'step' => 'claim',
            'steps_done' => [],
        ]);

        // The attribution record (C-1): channel, entry point,
        // completion state, timestamps. No phone, no shop name.
        $attributionToken = OpaqueToken::generate();
        $attribution = DemoAttribution::create([
            'token_hash' => OpaqueToken::hash($attributionToken),
            'channel' => 'embedded',
            'entry_point' => $entryPoint,
            'completion_state' => 'started',
            'expires_at' => now()->addDays((int) config('demo.attribution_days', 7)),
        ]);

        $this->sessions->put($session, ['attribution_id' => $attribution->id]);

        DemoEvent::record($session->data['session_key'], 'demo_started', null, $entryPoint);
        $this->demo->recordStart((string) $request->ip());

        $response = redirect()->route('demo.claim');
        $response->withCookie($this->sessions->cookie($token));
        $response->withCookie($this->attributionCookie($attributionToken));

        return $response;
    }

    /**
     * S1, the claim landing: Juniper's claim page for the demo
     * offer (shop name with its Demo shop chip, the reward
     * offer, what happens next in one line).
     */
    public function claim(Request $request): View|RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $this->sessions->put($session, ['step' => 'claim']);

        return view('demo.claim', ['business' => $this->business()]);
    }

    /**
     * S1's button: a plain hop to the form, so the form's own
     * GET is the single arrival that fires claim_viewed.
     */
    public function claimNext(Request $request): RedirectResponse
    {
        return redirect()->route('demo.form');
    }

    /**
     * S1's tap lands here: arriving at the form is the
     * claim_viewed step event (a view alone is not a step).
     */
    public function form(Request $request): View|RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        // A minted claim never re-enters the form: Back from
        // the token screen returns to the token, so no second
        // claim can mint (Section 3 loop rules).
        if (! empty($session->data['referral_id'])) {
            return redirect()->route('demo.reward');
        }

        $this->stepDone($session, 'claim_viewed');
        $this->sessions->put($session, ['step' => 'form']);

        return view('demo.form', [
            'business' => $this->business(),
            'draft' => $session->data['draft'] ?? [],
            'examplePhone' => (string) config('demo.example_phone_display'),
        ]);
    }

    /**
     * S2 submit: the real claim form's validation, then the
     * demo gateway starts a verification whose code the next
     * screen shows. The typed number goes ONLY into the demo
     * session store (Section 5.2).
     */
    public function submitForm(Request $request): RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        if (! empty($session->data['referral_id'])) {
            return redirect()->route('demo.reward');
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:254'],
            'consent' => ['nullable'],
        ], [
            'first_name.required' => 'Enter your first name.',
            'first_name.max' => 'That first name is too long.',
            'last_name.required' => 'Enter your last name.',
            'last_name.max' => 'That last name is too long.',
            'phone.required' => 'Enter your mobile phone number.',
            'phone.max' => 'That phone number is too long.',
            'email.email' => 'Enter a valid email address.',
            'email.max' => 'That email address is too long.',
        ]);

        $phone = PhoneNumber::normalizeToE164((string) $data['phone']);

        if ($phone === null) {
            return back()->withErrors(['phone' => 'Enter a valid US phone number.'])->withInput();
        }

        if (! $request->boolean('consent')) {
            return back()->withErrors(['consent' => 'Please agree to the texts so we can send your code and your reward updates.'])->withInput();
        }

        $pending = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone_e164' => $phone,
            'consent' => true,
        ];

        $this->sessions->put($session, [
            'pending' => $pending,
            'draft' => [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => PhoneNumber::formatForDisplay($phone),
                'consent' => true,
            ],
            'step' => 'verify',
        ]);

        $this->issueCode($session, $phone);
        $this->stepDone($session, 'form_submitted');

        return redirect()->route('demo.verify');
    }

    /**
     * S3, verification: the honesty line and the code card in
     * place of the sent-code claim. The real REF-VER-01 string
     * never renders here (acceptance A-5). A dead code renders
     * the exhausted state with its two honest exits.
     */
    public function verifyForm(Request $request): View|RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $pending = $session->data['pending'] ?? null;
        $verify = $session->data['verify'] ?? null;

        if ($pending === null || $verify === null) {
            return redirect()->route('demo.form');
        }

        return view('demo.verify', [
            'business' => $this->business(),
            'code' => $verify['code'],
            'phoneDisplay' => PhoneNumber::formatForDisplay($pending['phone_e164']),
            'error' => session('demo_verify_error'),
            'notice' => session('demo_verify_notice'),
            'exhausted' => (bool) ($verify['dead'] ?? false),
        ]);
    }

    /**
     * S3 submit: the demo gateway checks the code with the real
     * behaviour in kind (Section 4.3): a wrong code names the
     * true remaining tries, the code stays valid, exhaustion
     * kills it and offers only the two honest exits.
     */
    public function verifyCheck(Request $request): RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $pending = $session->data['pending'] ?? null;
        $verify = $session->data['verify'] ?? null;

        if ($pending === null || $verify === null) {
            return redirect()->route('demo.form');
        }

        if (! $request->filled('code')) {
            $assembled = collect(range(1, 6))
                ->map(fn (int $i) => (string) $request->input('code_'.$i, ''))
                ->implode('');
            $request->merge(['code' => $assembled]);
        }

        if (! $request->filled('code')) {
            return redirect()->route('demo.verify')
                ->with('demo_verify_error', 'Enter the 6-digit code shown above.');
        }

        if (($verify['dead'] ?? false) === true) {
            return redirect()->route('demo.verify');
        }

        $this->gateway->restore($pending['phone_e164'], (string) $verify['code'], (int) ($verify['attempts'] ?? 0));
        $result = $this->gateway->check($pending['phone_e164'], (string) $request->input('code'));
        $attempts = $this->gateway->checksFor($pending['phone_e164']);
        $maxAttempts = (int) config('demo.code.max_attempts', 5);

        if ($result['outcome'] !== 'approved') {
            $verify['attempts'] = $attempts;

            if ($attempts >= $maxAttempts) {
                $verify['dead'] = true;
            }

            $this->sessions->put($session, ['verify' => $verify]);

            if (($verify['dead'] ?? false) === true) {
                return redirect()->route('demo.verify');
            }

            $left = max(0, $maxAttempts - $attempts);
            $message = 'That code did not match. You have '.$left.' '.($left === 1 ? 'try' : 'tries').' left.';

            return redirect()->route('demo.verify')->with('demo_verify_error', $message);
        }

        // Approved: complete the claim on the real machinery's
        // shape. The pending and its code die here; the typed
        // number stays only as the session's carryover leg
        // (C-2) until the session itself ends.
        $done = $this->demo->completeClaim($this->business(), $session, [
            'first_name' => $pending['first_name'],
            'last_name' => $pending['last_name'],
            'consent' => (bool) $pending['consent'],
        ], $request->ip());

        $this->sessions->put($session, [
            'referral_id' => $done['referral']->id,
            'membership_id' => $done['membership']->id,
            'customer_id' => $done['customer']->id,
            'step' => 'reward',
        ]);
        $this->sessions->forget($session, 'pending');
        $this->sessions->forget($session, 'verify');

        $this->stepDone($session, 'verification_completed');
        $this->stepDone($session, 'reward_unlocked');

        return redirect()->route('demo.reward');
    }

    /**
     * "Show a new code" (Section 4.2): a NEW code is generated
     * and the old one invalidated first; only then does the
     * screen confirm. The RC-6 rule binds: never confirm an
     * action that did not happen.
     */
    public function newCode(Request $request): RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $pending = $session->data['pending'] ?? null;

        if ($pending === null) {
            return redirect()->route('demo.form');
        }

        $this->issueCode($session, $pending['phone_e164']);

        return redirect()->route('demo.verify')
            ->with('demo_verify_notice', 'A new code is shown above.');
    }

    /**
     * "Use a different number": the outstanding code dies, the
     * typed details ride back to the form as its draft, and
     * the replaced number leaves nothing behind (it was never
     * stored anywhere but this session, and the session now
     * holds the draft's formatted copy for the form alone,
     * exactly as the real flow's change-number leg does).
     */
    public function changeNumber(Request $request): RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $this->sessions->forget($session, 'pending');
        $this->sessions->forget($session, 'verify');
        $this->sessions->put($session, ['step' => 'form']);

        return redirect()->route('demo.form');
    }

    /**
     * S4, reward unlocked (the aha): the real token screen's
     * shape. A real single-use demo token exists behind it.
     */
    public function reward(Request $request): View|RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $referral = $this->sessionReferral($session);

        if ($referral === null) {
            return redirect()->route('demo.form');
        }

        if ($referral->state === Referral::STATE_REDEEMED) {
            return redirect()->route('demo.coda');
        }

        $this->sessions->put($session, ['step' => 'reward']);

        return view('demo.reward', [
            'business' => $this->business(),
            'referral' => $referral,
            'token' => $referral->redeem_token_encrypted,
            'qrUrl' => route('demo.qr', ['token' => $referral->redeem_token_encrypted]),
            'counterUrl' => route('demo.counter', ['token' => $referral->redeem_token_encrypted]),
        ]);
    }

    /**
     * The demo token's QR: served only to the session that
     * owns the claim, encoding the demo-guarded counter URL.
     */
    public function qr(Request $request, string $token): Response
    {
        $session = $this->sessions->fromRequest($request);

        // A-18 Finding 4: the QR resolves through the SAME
        // both-stamps guard as the counter, not session
        // equality alone. A stamp mismatch row's token is
        // never served here.
        if ($session === null || $this->guardedReferral($session, $token) === null) {
            abort(404);
        }

        return response(QrImage::svg(route('demo.counter', ['token' => $token])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    /**
     * S5, the counter moment: the staff side of the session's
     * own token (Section 6). Any other token, including a real
     * business's live token, gets the generic not-active
     * answer with no detail revealed (acceptance A-11).
     */
    public function counter(Request $request, string $token): View|RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $referral = $this->guardedReferral($session, $token);

        if ($referral === null) {
            return view('demo.refusal', ['business' => $this->business()]);
        }

        if ($referral->state === Referral::STATE_REDEEMED) {
            return view('demo.counter-done', [
                'business' => $this->business(),
                'referral' => $referral,
            ]);
        }

        $this->sessions->put($session, ['step' => 'counter']);

        return view('demo.counter', [
            'business' => $this->business(),
            'referral' => $referral,
            'friend' => $referral->referredCustomer,
            'token' => $token,
            'nonce' => $this->nonces->issue('referral', $referral->id),
            'error' => session('demo_counter_error'),
        ]);
    }

    /**
     * The Mark as Redeemed press: the real button, the real
     * nonce discipline, the real redemption service, demo
     * rows only.
     */
    public function counterConfirm(Request $request, string $token): RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $referral = $this->guardedReferral($session, $token);

        if ($referral === null) {
            return view('demo.refusal', ['business' => $this->business()]);
        }

        if (! $this->nonces->consume('referral', $referral->id, $request->input('nonce'))) {
            if ($referral->fresh()?->state === Referral::STATE_REDEEMED) {
                return redirect()->route('demo.counter', ['token' => $token]);
            }

            return redirect()->route('demo.counter', ['token' => $token])
                ->with('demo_counter_error', 'For security, this screen had expired. Here is a fresh one.');
        }

        $result = $this->redemption->redeemReferral($referral, null, $request->ip());

        if (($result['outcome'] ?? null) !== 'redeemed') {
            return redirect()->route('demo.counter', ['token' => $token]);
        }

        $this->sessions->put($session, ['step' => 'coda', 'completed' => true]);

        $attribution = $this->attributionFor($session);

        if ($attribution !== null) {
            $attribution->forceFill([
                'demo_completed' => true,
                'completion_state' => 'completed',
            ])->save();
        }

        $this->stepDone($session, 'counter_completed');
        DemoEvent::record($session->data['session_key'], 'demo_completed', null, $session->data['entry_point'] ?? null);

        return redirect()->route('demo.counter', ['token' => $token]);
    }

    /**
     * S6, the owner-side coda (Section 7): what the business
     * got, what it costs (the promises register's lines only),
     * the pitch, the mirror, and the signup CTA.
     */
    public function coda(Request $request): View|RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);

        if ($session === null) {
            return $this->noSession($request);
        }

        $referral = $this->sessionReferral($session);

        if ($referral === null || $referral->state !== Referral::STATE_REDEEMED) {
            return redirect()->to($this->stepUrl($session));
        }

        $this->stepDone($session, 'coda_viewed');
        $this->sessions->put($session, ['step' => 'coda']);

        return view('demo.coda', [
            'business' => $this->business(),
            'referral' => $referral,
        ]);
    }

    /**
     * The signup exits: the banner's persistent exit (S1 to
     * S5) and the coda's CTA both land here (Section 8). What
     * carries is ruled: the attribution token always; the
     * phone, shop name, and trade prefill only from the LIVE
     * session, as prefills in the prospect's own signup form.
     * Demo activity never carries (C-5).
     */
    public function exit(Request $request): RedirectResponse|Response
    {
        $session = $this->sessions->fromRequest($request);
        $fromStep = (string) ($session?->data['step'] ?? 'claim');
        $isCoda = $request->input('from') === 'coda';

        $shopName = trim((string) $request->input('shop_name', ''));
        $trade = (string) $request->input('trade', '');

        if ($session !== null) {
            $this->stepDone($session, $isCoda ? 'coda_cta_tapped' : 'exit_tapped', $fromStep);

            $attribution = $this->attributionFor($session);

            if ($attribution !== null) {
                $attribution->forceFill([
                    'completion_state' => ($session->data['completed'] ?? false) ? 'completed' : 'left',
                    'at_step' => $fromStep,
                    'trade_chip' => in_array($trade, array_keys(self::TRADE_TYPES), true) ? $trade : $attribution->trade_chip,
                ])->save();
            }

            $prefill = (array) $request->session()->get('signup.prefill', []);

            if ($shopName !== '') {
                $prefill['business_name'] = $shopName;
            }

            if (in_array($trade, array_keys(self::TRADE_TYPES), true)) {
                $prefill['business_type'] = self::TRADE_TYPES[$trade];
            }

            // C-2 (A-18 Finding 1): NO copy of the phone is
            // made here. The number lives only in the demo
            // session store; the signup form reads it from the
            // LIVE demo session at render time
            // (RegisteredUserController::create), so the
            // prefill dies at the exact moment the demo
            // session expires and can never outlive it in the
            // main Laravel session.

            if ($prefill !== []) {
                $request->session()->put('signup.prefill', $prefill);
            }
        }

        return redirect()->route('register');
    }

    // -- internals -------------------------------------------------

    private function business(): Business
    {
        return $this->demo->business() ?? $this->demo->ensureBusiness();
    }

    /**
     * Generate a fresh code for the session's pending number
     * through the demo gateway and store it in the session
     * (the session record is the durable store; the gateway's
     * own store is per-request).
     */
    private function issueCode(DemoSession $session, string $phoneE164): void
    {
        $this->gateway->start($phoneE164);
        $code = $this->gateway->codeFor($phoneE164);

        $this->sessions->put($session, ['verify' => [
            'code' => $code,
            'attempts' => 0,
            'dead' => false,
        ]]);
    }

    /**
     * Fire a step event once per session per step.
     */
    private function stepDone(DemoSession $session, string $step, ?string $atStep = null): void
    {
        $done = (array) ($session->data['steps_done'] ?? []);

        if (in_array($step, $done, true) && $atStep === null) {
            return;
        }

        DemoEvent::record(
            $session->data['session_key'],
            'demo_step_completed',
            $step,
            $session->data['entry_point'] ?? null,
            $atStep,
        );

        if ($atStep === null) {
            $done[] = $step;
            $this->sessions->put($session, ['steps_done' => $done]);
        }
    }

    private function sessionReferral(DemoSession $session): ?Referral
    {
        $id = (int) ($session->data['referral_id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        $referral = Referral::query()->whereKey($id)
            ->where('business_id', $this->business()->id)
            ->first();

        // A-18 Finding 4: the reward and coda screens (and
        // therefore the token display) apply the SAME
        // both-stamps rule as the counter guard: the owning
        // business must be flagged demo AND the claim itself
        // must be stamped source='demo'. A mismatch row
        // renders nothing here, exactly as at the counter.
        if ($referral === null
            || ! $referral->business->isDemo()
            || $referral->source !== 'demo') {
            return null;
        }

        return $referral;
    }

    /**
     * Resolve a presented token to a referral WITHOUT any
     * demo judgement; the guard applies the judgement.
     */
    private function resolveDemoToken(string $token): ?Referral
    {
        if (! OpaqueToken::isWellFormed($token)) {
            return null;
        }

        return Referral::query()->where('redeem_token_hash', OpaqueToken::hash($token))->first();
    }

    /**
     * The Section 6.2 guard, in one place: the token's OWN
     * business must be the demo business (resolved from the
     * token, never from a session claim or a URL parameter),
     * the claim must be a demo claim, and it must be THIS
     * session's claim.
     */
    private function guardedReferral(DemoSession $session, string $token): ?Referral
    {
        $referral = $this->resolveDemoToken($token);

        if ($referral === null
            || ! $referral->business->isDemo()
            || $referral->source !== 'demo'
            || (int) ($session->data['referral_id'] ?? 0) !== (int) $referral->id) {
            return null;
        }

        return $referral;
    }

    private function attributionFor(DemoSession $session): ?DemoAttribution
    {
        $id = (int) ($session->data['attribution_id'] ?? 0);

        return $id > 0 ? DemoAttribution::query()->find($id) : null;
    }

    /**
     * Where a session's current step lives (resume + bounces).
     */
    private function stepUrl(DemoSession $session): string
    {
        return match ($session->data['step'] ?? 'claim') {
            'form' => route('demo.form'),
            'verify' => route('demo.verify'),
            'reward' => route('demo.reward'),
            'coda' => route('demo.coda'),
            default => route('demo.claim'),
        };
    }

    /**
     * No live session: a request that HAD a demo cookie lands
     * on the timeout screen (its session died: expiry, reseed,
     * or invalidation); a request that never had one goes to
     * the start card.
     */
    private function noSession(Request $request): View|RedirectResponse
    {
        if ($this->sessions->hadCookie($request)) {
            return view('demo.timeout', ['business' => $this->demo->business()]);
        }

        return redirect()->route('demo.show');
    }

    private function attributionCookie(string $token): Cookie
    {
        return CookieFacade::make(
            (string) config('demo.attribution_cookie'),
            $token,
            (int) config('demo.attribution_days', 7) * 24 * 60,
            (string) config('session.path'),
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        );
    }
}
