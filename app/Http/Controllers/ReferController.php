<?php

namespace App\Http\Controllers;

use App\Jobs\SendFriendLinkSms;
use App\Models\Business;
use App\Models\Customer;
use App\Models\FlowSession;
use App\Models\Membership;
use App\Models\QrCode;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Flow\FlowSessionManager;
use App\Services\Referral\EnrollmentService;
use App\Services\Referral\VerificationService;
use App\Services\Security\TurnstileVerifier;
use App\Support\OpaqueToken;
use App\Support\PhoneNumber;
use App\Support\QrImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public customer flow (spec Section 3): QR landing,
 * enrollment, verification, the share page, the friend claim, and
 * the customer receipt pages R7 and R9. Flow state lives in the
 * server-side 30-minute flow session (AUTH-40), never in URLs
 * beyond the presented token. The session record is resolved per
 * request; a cookie is attached only when a new record is minted.
 */
class ReferController extends Controller
{
    public function __construct(
        private FlowSessionManager $flows,
        private VerificationService $verification,
        private EnrollmentService $enrollment,
        private TurnstileVerifier $turnstile,
        private \App\Services\Security\TokenResolutionGuard $resolutions,
    ) {}

    public function landing(Request $request, string $token): Response|RedirectResponse
    {
        $qr = QrCode::query()->where('token_hash', OpaqueToken::hash($token))->first();

        if ($qr === null) {
            return $this->resolutionFailure($request, null, 'inactive_qr', 'unknown_token');
        }

        if ($qr->state !== 'active') {
            return $this->resolutionFailure($request, $qr->business, 'inactive_qr', 'revoked_token');
        }

        $business = $qr->business;

        if (! $this->flowIsLive($business)) {
            return $this->message('not_active');
        }

        [$flow, $newToken] = $this->beginFlow($request, $business);

        return $this->respond(response()->view('flow.landing', [
            'business' => $business,
            'token' => $token,
            'phoneHelp' => $this->phoneConfirmationLine($request),
            'draft' => $this->draftFor($flow, $business),
        ]), $newToken);
    }

    /**
     * The phone component's confirmation line (design Section
     * 2.5): when normalization changes the digits the customer
     * typed, the field shows the normalized form before any code
     * is sent ("We will text +1 (212) 555-0142"). Null when the
     * typed digits already are the normalized digits, or when no
     * valid number has been typed yet.
     */
    private function phoneConfirmationLine(Request $request): ?string
    {
        $typed = $request->old('phone');

        if (! is_string($typed) || $typed === '') {
            return null;
        }

        $normalized = PhoneNumber::normalizeToE164($typed);

        if ($normalized === null) {
            return null;
        }

        $typedDigits = (string) preg_replace('/\D+/', '', $typed);

        if ($typedDigits === substr($normalized, 1)) {
            return null;
        }

        return 'We will text '.PhoneNumber::formatForDisplay($normalized);
    }

    public function enroll(Request $request, string $token): Response|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:254'],
            'consent' => ['nullable'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ], self::enrollmentMessages());

        [$flow, $newToken] = $this->flowFor($request);
        $qr = $this->enrollment->resolveQrForSubmit(
            $token,
            $flow !== null ? $this->flows->get($flow, 'qr_business_id') : null,
            $flow?->created_at,
        );

        if ($qr === null) {
            return $this->resolutionFailure($request, null, 'inactive_qr', 'unknown_token');
        }

        $business = $qr->business;

        if (! $this->flowIsLive($business)) {
            return $this->message('not_active');
        }

        if (! $this->turnstile->passes($request->input('cf-turnstile-response'), (string) $request->ip())) {
            return back()->withErrors(['turnstile' => 'We could not confirm this submission. Please try again.'])->withInput();
        }

        $phone = $this->usPhone((string) $data['phone']);

        if ($phone === null) {
            return back()->withErrors(['phone' => 'Enter a valid US phone number.'])->withInput();
        }

        // The step-1 consent panel is a real agreement, not a
        // decoration (round-2 audit F02): without it the flow
        // does not advance and no code is requested, because the
        // code and the reward texts both ride on this consent.
        if (! $request->boolean('consent')) {
            return back()->withErrors(['consent' => 'Please agree to the texts so we can send your code and your reward updates.'])->withInput();
        }

        if ($flow === null) {
            [$flow, $newToken] = $this->flows->start([]);
        }

        // A fresh enrollment on this device starts fresh: a leftover
        // verified marker from an earlier business must not link
        // numbers across businesses (EC-16 is same-flow).
        $this->flows->forget($flow, 'verified');
        $this->flows->put($flow, [
            'qr_business_id' => $business->id,
            'scan_business_id' => $business->id,
        ]);

        return $this->startVerification($request, $flow, $newToken, $business, [
            'kind' => 'enroll',
            'business_id' => $business->id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone_e164' => $phone,
            'email' => $data['email'] ?? null,
            'consent' => $request->boolean('consent'),
            'return' => ['route' => 'refer.landing', 'params' => ['token' => $token]],
        ]);
    }

    /**
     * The enrollment forms' validation voice (design Section 4.4,
     * F-03): plain language that says what to enter, never a
     * framework default.
     *
     * @return array<string, string>
     */
    private static function enrollmentMessages(): array
    {
        return [
            'first_name.required' => 'Enter your first name.',
            'first_name.max' => 'That first name is too long.',
            'last_name.required' => 'Enter your last name.',
            'last_name.max' => 'That last name is too long.',
            'phone.required' => 'Enter your mobile phone number.',
            'phone.max' => 'That phone number is too long.',
            'email.email' => 'Enter a valid email address.',
            'email.max' => 'That email address is too long.',
        ];
    }

    public function verifyForm(Request $request): Response|RedirectResponse
    {
        [$flow] = $this->flowFor($request);
        $pending = $flow !== null ? $this->flows->get($flow, 'pending') : null;

        if ($pending === null) {
            return $this->flowBounce($request, $flow);
        }

        return response()->view('flow.verify', [
            'business' => Business::query()->find($pending['business_id']),
            'maskedPhone' => PhoneNumber::mask($pending['phone_e164']),
            'channel' => $pending['channel'] ?? 'sms',
            'error' => session('verify_error'),
            'notice' => session('verify_notice'),
        ]);
    }

    public function verifyCheck(Request $request): Response|RedirectResponse
    {
        [$flow] = $this->flowFor($request);
        $pending = $flow !== null ? $this->flows->get($flow, 'pending') : null;

        if ($pending === null) {
            return $this->flowBounce($request, $flow);
        }

        if (! $request->filled('code')) {
            $assembled = collect(range(1, 6))
                ->map(fn (int $i) => (string) $request->input('code_'.$i, ''))
                ->implode('');
            $request->merge(['code' => $assembled]);
        }

        if (! $request->filled('code')) {
            return redirect()->route('refer.verify')
                ->with('verify_error', 'Enter the 6-digit code we sent you.');
        }

        $request->validate(['code' => ['required', 'string', 'max:10']]);

        $business = Business::query()->findOrFail($pending['business_id']);
        $checks = (int) ($pending['checks'] ?? 0) + 1;

        if ($checks > (int) config('referrals.verify.checks_per_pending')) {
            // The locked screen (design R3) still offers the one
            // designed exit back to the start of this flow, so the
            // pending's return target is captured before the
            // pending is forgotten (F-05).
            $returnUrl = isset($pending['return']['route'])
                ? route($pending['return']['route'], $pending['return']['params'] ?? [])
                : null;

            $this->flows->forget($flow, 'pending');

            return $this->message('too_many_codes', $business, null, $returnUrl);
        }

        $pending['checks'] = $checks;
        $this->flows->put($flow, ['pending' => $pending]);

        $result = $this->verification->check($pending['phone_e164'], (string) $request->input('code'));
        $outcome = $result['outcome'];

        if ($outcome === 'provider_error') {
            return redirect()->route('refer.verify')
                ->with('verify_error', 'Something went wrong on our end. Please try again in a moment.');
        }

        if ($outcome !== 'approved') {
            // A denied check and a wrong code are indistinguishable
            // by design (SMS-08). The message carries the remaining
            // count, concretely (Section 4.4 rule 5, F-15); at zero
            // it also says what to do next (T4-F5): the next submit
            // ends the attempt, and Resend starts a fresh code with
            // a fresh budget.
            $triesLeft = max(0, (int) config('referrals.verify.checks_per_pending') - $checks);

            $message = $triesLeft === 0
                ? 'That code did not match. That was the last try for this code. Tap Resend code to get a fresh one.'
                : 'That code did not match. You have '.$triesLeft.' '.($triesLeft === 1 ? 'try' : 'tries').' left.';

            return redirect()->route('refer.verify')
                ->with('verify_error', $message);
        }

        $this->flows->forget($flow, 'pending');

        // EC-16: this flow was already verified as a different
        // number; completing a second verification links them.
        $prior = $this->flows->get($flow, 'verified');

        if ($pending['kind'] === 'enroll') {
            $scan = (int) ($this->flows->get($flow, 'scan_business_id') ?? 0) === $business->id;
            $this->flows->forget($flow, 'scan_business_id');

            $done = $this->enrollment->completeEnrollment($business, $pending, $request->ip(), $this->deviceSignal($request), $scan);

            $this->linkIfNeeded($flow, $prior, $pending, $done['customer'], $business, $request);

            $this->flows->put($flow, ['verified' => [
                'customer_id' => $done['customer']->id,
                'membership_id' => $done['membership']->id,
                'business_id' => $business->id,
                'phone_e164' => $pending['phone_e164'],
                // The share page greets a member whose membership
                // already existed as returning (REF-SHARE-01).
                'returning' => ! $done['created_membership'],
            ]]);

            [, $rotated] = $this->flows->rotate($flow);

            return $this->respond(redirect()->route('refer.share'), $rotated);
        }

        $referrerMembership = Membership::query()->findOrFail($pending['referrer_membership_id']);
        $done = $this->enrollment->completeClaim($referrerMembership, $pending, $request->ip(), $this->deviceSignal($request));

        if (in_array($done['kind'], ['self_phone', 'self_email', 'owner_phone'], true)) {
            $selfView = response()->view('flow.self', [
                'business' => $business,
                'variant' => $done['kind'],
                'returnUrl' => route('refer.friend', ['token' => $pending['return']['params']['token']]),
            ]);

            if ($done['kind'] === 'owner_phone') {
                return $selfView;
            }

            // A self-claim means the verified person IS the
            // referrer: verify the flow as them, so the self
            // screen's way forward (their own share page, F-27)
            // actually resolves instead of bouncing home.
            $this->flows->put($flow, ['verified' => [
                'customer_id' => $referrerMembership->customer_id,
                'membership_id' => $referrerMembership->id,
                'business_id' => $business->id,
                'phone_e164' => $pending['phone_e164'],
                'returning' => true,
            ]]);

            [, $rotated] = $this->flows->rotate($flow);

            return $this->respond($selfView, $rotated);
        }

        $friendCustomer = $done['membership']->customer;
        $this->linkIfNeeded($flow, $prior, $pending, $friendCustomer, $business, $request);

        $this->flows->put($flow, ['verified' => [
            'customer_id' => $friendCustomer->id,
            'membership_id' => $done['membership']->id,
            'business_id' => $business->id,
            'phone_e164' => $pending['phone_e164'],
        ]]);

        if ($done['kind'] === 'created' && ($pending['send_link'] ?? false)) {
            SendFriendLinkSms::dispatch($done['referral']->id);
        }

        [, $rotated] = $this->flows->rotate($flow);

        return $this->respond(redirect()->route('refer.redeem.page', ['token' => $done['referral']->redeem_token_encrypted]), $rotated);
    }

    public function resend(Request $request): Response|RedirectResponse
    {
        [$flow] = $this->flowFor($request);
        $pending = $flow !== null ? $this->flows->get($flow, 'pending') : null;

        if ($pending === null) {
            return $this->flowBounce($request, $flow);
        }

        $business = Business::query()->findOrFail($pending['business_id']);

        if (($pending['last_start_at'] ?? 0) + (int) config('referrals.verify.resend_cooldown_seconds') > now()->timestamp) {
            return redirect()->route('refer.verify')
                ->with('verify_notice', 'We sent you a new code. It can take a minute to arrive.');
        }

        if ((int) ($pending['starts'] ?? 1) >= (int) config('referrals.verify.starts_per_flow_session')) {
            return $this->message('rate_limited', $business, null, route('refer.verify'));
        }

        $result = $this->verification->start($pending['phone_e164'], (string) $request->ip(), $business, $pending['channel'] ?? 'sms');

        if ($result['outcome'] === 'rate_limited') {
            return $this->message('rate_limited', $business, null, route('refer.verify'));
        }

        if ($result['outcome'] !== 'started') {
            return $this->message('provider_error', $business, null, route('refer.verify'));
        }

        $pending['checks'] = 0;
        $pending['starts'] = (int) ($pending['starts'] ?? 1) + 1;
        $pending['last_start_at'] = now()->timestamp;
        $this->flows->put($flow, ['pending' => $pending]);

        return redirect()->route('refer.verify')
            ->with('verify_notice', 'We sent you a new code. It can take a minute to arrive.');
    }

    public function changeNumber(Request $request): RedirectResponse
    {
        [$flow] = $this->flowFor($request);
        $pending = $flow !== null ? $this->flows->get($flow, 'pending') : null;

        if ($flow !== null) {
            $this->flows->forget($flow, 'pending');
        }

        if ($pending === null) {
            return $this->flowBounce($request, $flow);
        }

        // Only the pending code dies here (T4-F3): the details the
        // customer typed ride back to the form as its draft, the
        // same shape startVerification saves, so changing one
        // digit never means retyping everything or re-consenting.
        $this->flows->put($flow, ['draft' => [
            'business_id' => $pending['business_id'],
            'first_name' => $pending['first_name'] ?? '',
            'last_name' => $pending['last_name'] ?? '',
            'phone' => PhoneNumber::formatForDisplay($pending['phone_e164']),
            'email' => $pending['email'] ?? '',
            'consent' => (bool) ($pending['consent'] ?? false),
        ]]);

        return redirect()->route($pending['return']['route'], $pending['return']['params']);
    }

    public function share(Request $request): Response|RedirectResponse
    {
        [$flow] = $this->flowFor($request);
        $verified = $flow !== null ? $this->flows->get($flow, 'verified') : null;

        if ($verified === null) {
            return $this->flowBounce($request, $flow);
        }

        $membership = Membership::query()->with(['business', 'customer'])->find($verified['membership_id']);

        if ($membership === null) {
            return $this->flowBounce($request, $flow);
        }

        $business = $membership->business;
        $shareUrl = route('refer.friend', ['token' => $membership->plainShareToken()]);
        $shareMessage = $business->friend_reward_text !== null
            ? $membership->customer->fullName().' invited you to get your Reward: '.$business->friend_reward_text.' at '.$business->name.'. Click here to claim the reward!'
            : $membership->customer->fullName().' invited you to '.$business->name.'. Click here to claim the reward!';

        return response()->view('flow.share', [
            'business' => $business,
            'customer' => $membership->customer,
            'shareUrl' => $shareUrl,
            'shareMessage' => $shareMessage,
            'shareQrUrl' => route('refer.share.qr'),
            'referralCount' => (int) $membership->referral_count,
            'returning' => (bool) ($verified['returning'] ?? false),
            // OD-2: an unset referrer reward reads as the friend
            // reward, the same default the rest of the flow uses.
            'referrerRewardText' => $business->referrer_reward_text ?? $business->friend_reward_text,
        ]);
    }

    public function shareQr(Request $request): Response|RedirectResponse
    {
        [$flow] = $this->flowFor($request);
        $verified = $flow !== null ? $this->flows->get($flow, 'verified') : null;

        if ($verified === null) {
            return $this->flowBounce($request, $flow);
        }

        $membership = Membership::query()->find($verified['membership_id']);

        if ($membership === null) {
            return $this->flowBounce($request, $flow);
        }

        return response(QrImage::svg(route('refer.friend', ['token' => $membership->plainShareToken()])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    public function friendLanding(Request $request, string $token): Response
    {
        $membership = Membership::byShareToken($token);

        if ($membership === null) {
            return $this->resolutionFailure($request, null, 'bad_link', 'unknown_token');
        }

        $business = $membership->business;

        if (! $this->flowIsLive($business)) {
            return $this->message('not_active');
        }

        [$flow, $newToken] = $this->beginFlow($request, $business);

        return $this->respond(response()->view('flow.friend', [
            'business' => $business,
            'referrer' => $membership->customer,
            'token' => $token,
            'phoneHelp' => $this->phoneConfirmationLine($request),
            'draft' => $this->draftFor($flow, $business),
        ]), $newToken);
    }

    public function friendClaim(Request $request, string $token): Response|RedirectResponse
    {
        $membership = Membership::byShareToken($token);

        if ($membership === null) {
            return $this->resolutionFailure($request, null, 'bad_link', 'unknown_token');
        }

        $business = $membership->business;

        if (! $this->flowIsLive($business)) {
            return $this->message('not_active');
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:254'],
            'consent' => ['nullable'],
            'send_link' => ['nullable'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ], self::enrollmentMessages());

        if (! $this->turnstile->passes($request->input('cf-turnstile-response'), (string) $request->ip())) {
            return back()->withErrors(['turnstile' => 'We could not confirm this submission. Please try again.'])->withInput();
        }

        $phone = $this->usPhone((string) $data['phone']);

        if ($phone === null) {
            return back()->withErrors(['phone' => 'Enter a valid US phone number.'])->withInput();
        }

        [$flow, $newToken] = $this->flowFor($request);

        if ($flow === null) {
            [$flow, $newToken] = $this->flows->start([]);
        }

        return $this->startVerification($request, $flow, $newToken, $business, [
            'kind' => 'claim',
            'business_id' => $business->id,
            'referrer_membership_id' => $membership->id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone_e164' => $phone,
            'email' => $data['email'] ?? null,
            'consent' => $request->boolean('consent'),
            'send_link' => $request->boolean('send_link'),
            'return' => ['route' => 'refer.friend', 'params' => ['token' => $token]],
        ]);
    }

    public function redeemPage(Request $request, string $token): Response
    {
        $referral = Referral::query()
            ->where('redeem_token_hash', OpaqueToken::hash($token))
            ->first();

        if ($referral === null) {
            return $this->resolutionFailure($request, null, 'bad_link', 'unknown_token');
        }

        $referral->applyExpiry();
        $business = $referral->business;

        $state = match (true) {
            $referral->state === Referral::STATE_REDEEMED => 'redeemed',
            $referral->state === Referral::STATE_EXPIRED => 'expired',
            $referral->state === Referral::STATE_PENDING && $referral->hold => 'held',
            $referral->state === Referral::STATE_PENDING => 'show',
            default => 'unavailable',
        };

        $activeQr = $business->activeQrCode();

        return response()->view('flow.redeem', [
            'business' => $business,
            'referral' => $referral,
            'state' => $state,
            'qrUrl' => route('refer.redeem.qr', ['token' => $token]),
            'token' => $token,
            // The expired state's one exit (design R7): back to the
            // business's own landing, never a re-issue.
            'backUrl' => $activeQr !== null && $activeQr->plainToken() !== null
                ? route('refer.landing', ['token' => $activeQr->plainToken()])
                : null,
        ]);
    }

    public function redeemQr(Request $request, string $token): Response
    {
        return $this->tokenQrImage('redeem', $token);
    }

    public function claimPage(Request $request, string $token): Response
    {
        $reward = Reward::query()
            ->where('claim_token_hash', OpaqueToken::hash($token))
            ->first();

        if ($reward === null) {
            return $this->resolutionFailure($request, null, 'bad_link', 'unknown_token');
        }

        $reward->applyVesting();
        $business = $reward->business;

        // The closure wind-down end (spec Section 5.5): once the
        // 30 days have run out the reward expires and its claim
        // link returns the same generic page as a bad link.
        $reward->applyClosureExpiry();

        if ($reward->state === Reward::STATE_EXPIRED) {
            return $this->resolutionFailure($request, $business, 'bad_link', 'expired_token');
        }

        // The availability date appears on this page only inside
        // the referrer's own live flow session (G-17): a bare link
        // opened anywhere else shows the waiting state without it.
        [$flow] = $this->flowFor($request);
        $verified = $flow !== null ? $this->flows->get($flow, 'verified') : null;
        // The disclosure gate binds to the customer AND the
        // business the verification happened at; a verification at
        // another business never unlocks this business's date
        // (security verifier m-4).
        $inOwnSession = $verified !== null
            && (int) ($verified['customer_id'] ?? 0) === (int) $reward->membership->customer_id
            && (int) ($verified['business_id'] ?? 0) === (int) $reward->business_id;

        $state = match (true) {
            $reward->state === Reward::STATE_CLAIMED => 'claimed',
            $reward->state === Reward::STATE_DENIED => 'denied',
            $reward->hold => 'held',
            $reward->state === Reward::STATE_AVAILABLE => 'ready',
            default => 'waiting',
        };

        return response()->view('flow.claim', [
            'business' => $business,
            'reward' => $reward,
            'state' => $state,
            'showAvailableDate' => $inOwnSession,
            'qrUrl' => route('refer.claim.qr', ['token' => $token]),
        ]);
    }

    public function claimQr(Request $request, string $token): Response
    {
        return $this->tokenQrImage('claim', $token);
    }

    /**
     * The request's flow session, if one is live.
     *
     * @return array{0: ?FlowSession, 1: null}
     */
    private function flowFor(Request $request): array
    {
        return [$this->flows->fromRequest($request), null];
    }

    /**
     * Where a customer lands when they open a flow step with
     * nothing behind it (T4-F4): the start of the flow they were
     * in, when this session knows the business, with a plain
     * explanation either way. The old answer was a silent redirect
     * to the marketing homepage, a page pitched at business
     * owners, which left a customer who reopened a bookmarked
     * step with no idea what happened.
     */
    private function flowBounce(Request $request, ?FlowSession $flow): RedirectResponse
    {
        $businessId = $flow !== null
            ? (int) ($this->flows->get($flow, 'qr_business_id') ?? $this->flows->get($flow, 'scan_business_id') ?? 0)
            : 0;
        $business = $businessId > 0 ? Business::query()->find($businessId) : null;
        $token = $business?->activeQrCode()?->plainToken();

        if ($business !== null && $token !== null) {
            return redirect()->route('refer.landing', ['token' => $token])
                ->with('status', 'That step is not open anymore. Start again here: enter your phone number and we will text you a fresh code.');
        }

        return redirect()->route('home')
            ->with('status', 'That step is not open anymore. If a business gave you a code or a link, use it to start again.');
    }

    /**
     * Start (or reuse) the flow session and remember which business
     * the customer arrived at, for the scan event and the QR
     * revocation grace.
     *
     * @return array{0: FlowSession, 1: ?string} the session and the
     *   new cookie token when one was minted
     */
    private function beginFlow(Request $request, Business $business): array
    {
        $flow = $this->flows->fromRequest($request);

        if ($flow === null) {
            [$flow, $token] = $this->flows->start([
                'qr_business_id' => $business->id,
                'scan_business_id' => $business->id,
            ]);

            return [$flow, $token];
        }

        if ((int) ($this->flows->get($flow, 'qr_business_id') ?? 0) !== $business->id) {
            $this->flows->put($flow, [
                'qr_business_id' => $business->id,
                'scan_business_id' => $business->id,
            ]);
        }

        return [$flow, null];
    }

    private function flowIsLive(Business $business): bool
    {
        return $business->status === 'active' && $business->flowIsActive();
    }

    /**
     * Start a verification for a pending flow context, applying the
     * landline fallback (voice call, then the designed blocked
     * error) and mapping every outcome to its designed state.
     *
     * @param  array<string, mixed>  $pending
     */
    private function startVerification(Request $request, FlowSession $flow, ?string $newToken, Business $business, array $pending): Response|RedirectResponse
    {
        // The typed details ride the flow session from here, so a
        // provider failure can send the customer back to a form
        // that still holds what they typed (standards G7: forms
        // never clear on error, and no error state is a dead end).
        $this->flows->put($flow, ['draft' => [
            'business_id' => $business->id,
            'first_name' => $pending['first_name'] ?? '',
            'last_name' => $pending['last_name'] ?? '',
            'phone' => isset($pending['phone_e164']) ? PhoneNumber::formatForDisplay($pending['phone_e164']) : '',
            'email' => $pending['email'] ?? '',
            'consent' => (bool) ($pending['consent'] ?? false),
        ]]);

        $returnUrl = isset($pending['return']['route'])
            ? route($pending['return']['route'], $pending['return']['params'] ?? [])
            : null;

        if ((int) ($this->flows->get($flow, 'flow_starts') ?? 0) >= (int) config('referrals.verify.starts_per_flow_session')) {
            return $this->message('rate_limited', $business, null, $returnUrl);
        }

        $result = $this->verification->start($pending['phone_e164'], (string) $request->ip(), $business);
        $channel = 'sms';

        if ($result['outcome'] === 'landline_blocked') {
            // EC-19: the number cannot receive texts; retry once by
            // voice call before showing the designed dead end.
            $result = $this->verification->start($pending['phone_e164'], (string) $request->ip(), $business, 'call');
            $channel = 'call';

            if ($result['outcome'] === 'landline_blocked') {
                return back()->withErrors(['phone' => 'That number cannot receive text messages. Please use a mobile number.'])->withInput();
            }
        }

        if ($result['outcome'] === 'rate_limited') {
            return $this->message('rate_limited', $business, null, $returnUrl);
        }

        if ($result['outcome'] !== 'started') {
            return $this->message('provider_error', $business, null, $returnUrl);
        }

        $this->flows->forget($flow, 'draft');
        $this->flows->put($flow, ['flow_starts' => (int) ($this->flows->get($flow, 'flow_starts') ?? 0) + 1]);

        $pending['channel'] = $channel;
        $pending['checks'] = 0;
        $pending['starts'] = 1;
        $pending['last_start_at'] = now()->timestamp;
        $this->flows->put($flow, ['pending' => $pending]);

        return $this->respond(redirect()->route('refer.verify'), $newToken);
    }

    /**
     * EC-16: when this flow was already verified as a different
     * customer, completing the new verification links the numbers
     * on the original identity.
     *
     * @param  array<string, mixed>|null  $prior
     * @param  array<string, mixed>  $pending
     */
    private function linkIfNeeded(FlowSession $flow, ?array $prior, array $pending, Customer $completedAs, Business $business, Request $request): void
    {
        if ($prior === null || ($prior['phone_e164'] ?? null) === $pending['phone_e164']) {
            return;
        }

        // EC-16 links numbers verified at the SAME business; a
        // leftover verified marker from another business never
        // links here (security verifier M-3).
        if (($prior['business_id'] ?? null) !== null && (int) $prior['business_id'] !== $business->id) {
            return;
        }

        $original = Customer::query()->find($prior['customer_id'] ?? null);

        if ($original !== null && $original->id !== $completedAs->id) {
            $this->enrollment->linkNumber($completedAs, $original, $business, $request->ip());
        }
    }

    /**
     * Normalize and geo-lock a flow phone number (EC-21): the
     * canonical normalizer accepts international E.164 for owner
     * identity, but the customer flow verifies +1 numbers only, and
     * validates that before any provider call.
     */
    private function usPhone(string $raw): ?string
    {
        $e164 = PhoneNumber::normalizeToE164($raw);

        if ($e164 === null || ! str_starts_with($e164, '+1') || strlen($e164) !== 12) {
            return null;
        }

        // NANP shape: area code and exchange both start 2-9.
        if ($e164[2] < '2' || $e164[5] < '2') {
            return null;
        }

        return $e164;
    }

    private function deviceSignal(Request $request): ?string
    {
        // The shipped client sends the signal as a form field
        // (resources/js/flow.js fills it from a per-device id);
        // the header remains accepted for API-shaped clients.
        $signal = $request->input('device_signal') ?? $request->header('X-Device-Signal');

        return is_string($signal) && $signal !== '' ? hash('sha256', $signal) : null;
    }

    /**
     * The saved form draft for this business, if the flow session
     * holds one (see startVerification).
     *
     * @return array<string, mixed>
     */
    private function draftFor(?FlowSession $flow, Business $business): array
    {
        $draft = $flow !== null ? $this->flows->get($flow, 'draft') : null;

        if (! is_array($draft) || (int) ($draft['business_id'] ?? 0) !== $business->id) {
            return [];
        }

        return $draft;
    }

    private function message(string $state, ?Business $business = null, ?int $status = null, ?string $returnUrl = null): Response
    {
        // The does-not-resolve family answers 404 (spec Section 3
        // Step 1, inventory REF-LAND-02): unknown, revoked, and
        // not-yet-active codes are indistinguishable and absent.
        // Past the failed-resolution budget the same body answers
        // 429 instead (Section 10.4).
        $status ??= in_array($state, ['inactive_qr', 'not_active', 'bad_link'], true) ? 404 : 200;

        return response()->view('flow.message', [
            'state' => $state,
            'business' => $business,
            'returnUrl' => $returnUrl,
            'waitMinutes' => (int) config('referrals.verify.code_ttl_minutes'),
        ], $status);
    }

    /**
     * A token presentation that does not resolve (Sections 3 and
     * 10.4): recorded against the shared budget; the rendered
     * body is the same generic page either way.
     */
    private function resolutionFailure(Request $request, ?Business $business, string $state, string $reason): Response
    {
        $exceeded = $this->resolutions->recordFailure($business, null, null, null, $reason, $request->ip());

        return $this->message($state, $business, $exceeded ? 429 : null);
    }

    private function respond(Response|RedirectResponse $response, ?string $newFlowToken): Response|RedirectResponse
    {
        if ($newFlowToken !== null) {
            $response->withCookie($this->flows->cookie($newFlowToken));
        }

        return $response;
    }

    /**
     * QR images resolve their token first (security verifier m-2):
     * the image endpoints serve only a live token's own image and
     * answer a generic 404 for anything else, so they cannot mint
     * official-looking QR art around arbitrary URLs.
     */
    private function tokenQrImage(string $kind, string $token): Response
    {
        $found = OpaqueToken::isWellFormed($token) && (
            $kind === 'redeem'
                ? Referral::query()->where('redeem_token_hash', OpaqueToken::hash($token))->exists()
                : Reward::query()->where('claim_token_hash', OpaqueToken::hash($token))->exists()
        );

        if (! $found) {
            abort(404);
        }

        // Both token kinds resolve at the one staff endpoint,
        // which tries referral then reward by hash (spec Section
        // 6.1 item 3).
        return response(QrImage::svg(route('redeem.show', ['token' => $token])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
