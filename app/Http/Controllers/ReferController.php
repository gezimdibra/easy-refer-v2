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
            return $this->message('not_active', $business);
        }

        [$flow, $newToken] = $this->beginFlow($request, $business, $qr);

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
            'last_name' => ['nullable', 'string', 'max:80'],
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
            return $this->message('not_active', $business);
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
            // QRK-05: the code this enrollment arrived through
            // (already resolved above, grace window included).
            'qr_code_id' => $qr->id,
        ]);

        return $this->startVerification($request, $flow, $newToken, $business, [
            'kind' => 'enroll',
            'business_id' => $business->id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? '',
            'phone_e164' => $phone,
            'email' => $data['email'] ?? null,
            'consent' => $request->boolean('consent'),
            'return' => ['route' => 'refer.landing', 'params' => ['token' => $token]],
        ]);
    }

    /**
     * The enrollment forms' validation voice (design Section 4.4,
     * F-03): plain language that says what to enter, never a
     * framework default. F-6 (effectiveness audit, 2026-10-29):
     * the last name is OPTIONAL on both flow forms (enroll and
     * friend claim) — no downstream surface spends it (staff
     * confirm reads first name only), so there is deliberately
     * no last_name.required message; an omitted last name is
     * stored as '' (the customers.last_name convention the
     * owner-add path already uses).
     *
     * @return array<string, string>
     */
    private static function enrollmentMessages(): array
    {
        return [
            'first_name.required' => 'Enter your first name.',
            'first_name.max' => 'That first name is too long.',
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

        // RC-3 (VOL-3): the step count branches on the pending's
        // kind. Enrollment genuinely has 3 steps (landing, code,
        // share); a friend claim has 2 (claim form, code) — the
        // visit page after it is the completion, not a step, so
        // "Step 2 of 3" on this path promised a Step 1 the friend
        // never saw and a Step 3 that does not exist.
        // RC-6 (VOL-3): the resend cooldown state rides to the
        // template so the button can render disabled with the
        // real remaining wait, instead of inviting a tap whose
        // send is suppressed (see resend()).
        $resendWaitSeconds = $this->resendWaitSeconds($pending);

        return response()->view('flow.verify', [
            'business' => Business::query()->find($pending['business_id']),
            'maskedPhone' => PhoneNumber::mask($pending['phone_e164']),
            'channel' => $pending['channel'] ?? 'sms',
            'error' => session('verify_error'),
            'notice' => session('verify_notice'),
            // ID-11: a coupon pending is a 2-step flow like a
            // friend claim (landing form, code), and its verify
            // screen carries the coupon delta line (COUP-22).
            'pendingKind' => $pending['kind'] ?? 'enroll',
            'stepTotal' => in_array($pending['kind'] ?? 'enroll', ['claim', 'coupon'], true) ? 2 : 3,
            'resendWaitSeconds' => $resendWaitSeconds,
            'resendWaitMessage' => $resendWaitSeconds > 0 ? $this->resendWaitMessage($resendWaitSeconds) : null,
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

        // CB-B1 (closed-business billing audit, 2026-10-27): the
        // completion step re-checks liveness. Every entry step
        // (landing, enroll, friend claim) gates on flowIsLive,
        // but a verification started while the business was live
        // could previously complete after the owner closed the
        // account: the completion created a referral closeAccount
        // never voided (it did not exist at closure), and its
        // redemption minted a new billing event — a closed
        // business accruing a brand-new charge. A pending flow
        // for a business that is no longer live completes
        // nothing; the answer is the same generic page the
        // flow's entry steps give for that business.
        // ID-11 exception: a coupon pending is NOT gated here.
        // Its activation predicate is couponFlowActive (no
        // friend-text leg), and its completion (mintClaim)
        // re-reads every gate and answers with the coupon
        // channel's own closed and limit pages, which are more
        // specific than this generic page in every state.
        if (($pending['kind'] ?? null) !== 'coupon' && ! $this->flowIsLive($business)) {
            return $this->message('not_active', $business);
        }

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

        if (($pending['kind'] ?? null) === 'coupon') {
            // ID-11 (S3): the coupon completion. No membership,
            // no referral, no reward, no billing: mintClaim
            // mints the claim itself, immediately, or names the
            // gate that stopped it. The verified marker is the
            // claim's customer, for session continuity only.
            $batch = \App\Models\CouponBatch::query()->findOrFail($pending['batch_id']);
            $result = app(\App\Services\Coupons\CouponService::class)->mintClaim($batch, $pending);

            if ($result['kind'] === 'minted') {
                $claim = $result['claim'];
                $this->flows->put($flow, ['verified' => [
                    'customer_id' => $claim->customer_id,
                    'business_id' => $business->id,
                    'phone_e164' => $pending['phone_e164'],
                ]]);
                [, $rotated] = $this->flows->rotate($flow);

                return $this->respond(redirect()->route('coupon.claim.page', ['token' => $claim->plainToken()]), $rotated);
            }

            if ($result['kind'] === 'limit' && $result['limit_claim'] !== null) {
                $limitClaim = $result['limit_claim'];
                $limitClaim->applyExpiry();

                return response()->view('coupon.limit', [
                    'business' => $business,
                    'claim' => $limitClaim,
                    'claimUrl' => $limitClaim->state === \App\Models\CouponClaim::STATE_READY && $limitClaim->plainToken() !== null
                        ? route('coupon.claim.page', ['token' => $limitClaim->plainToken()])
                        : null,
                ]);
            }

            return response()->view('coupon.closed', [
                'business' => $business,
                'form' => $result['kind'] === 'full' ? 'full' : ($result['kind'] === 'ended' ? 'ended' : 'paused'),
                'batch' => $batch,
            ]);
        }

        if ($pending['kind'] === 'enroll') {
            $scan = (int) ($this->flows->get($flow, 'scan_business_id') ?? 0) === $business->id;
            $this->flows->forget($flow, 'scan_business_id');

            // QRK-05: the code this flow arrived through stamps
            // the scan event (id + placement). The row may have
            // been revoked inside the grace window since; its
            // placement is still the truth about the scan.
            $scanQr = null;
            $scanQrCodeId = $this->flows->get($flow, 'qr_code_id');
            if ($scan && $scanQrCodeId !== null) {
                $scanQr = QrCode::query()->find((int) $scanQrCodeId);
            }

            $done = $this->enrollment->completeEnrollment($business, $pending, $request->ip(), $this->deviceSignal($request), $scan, $scanQr?->id, $scanQr?->placement);

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

        $resendWaitSeconds = $this->resendWaitSeconds($pending);

        if ($resendWaitSeconds > 0) {
            // RC-6 (VOL-3, STATE-BLIND COPY): inside the cooldown
            // the sender suppresses the send — nothing is sent —
            // so the flash must not claim a new code was. It
            // states the wait instead, with the real remaining
            // seconds from the pending's own last_start_at. The
            // rate-limit behaviour is unchanged: no provider
            // call, no budget consumed, same redirect.
            return redirect()->route('refer.verify')
                ->with('verify_notice', $this->resendWaitMessage($resendWaitSeconds));
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

    /**
     * The resend cooldown's remaining seconds for a pending
     * verification (RC-6): the pending's own last_start_at plus
     * the configured cooldown, against now. Zero once the
     * cooldown has elapsed. This is the one calculation both
     * the verify screen's disabled state and the resend tap's
     * flash read, so the two surfaces can never disagree about
     * whether a send is available.
     *
     * @param  array<string, mixed>  $pending
     */
    private function resendWaitSeconds(array $pending): int
    {
        $remaining = (int) ($pending['last_start_at'] ?? 0)
            + (int) config('referrals.verify.resend_cooldown_seconds')
            - now()->timestamp;

        return max(0, $remaining);
    }

    /**
     * The cooldown state's true sentence (RC-6): no code was
     * sent, and this is how long the wait actually is.
     */
    private function resendWaitMessage(int $seconds): string
    {
        return 'Please wait '.$seconds.' '.($seconds === 1 ? 'second' : 'seconds').' before asking for a new code.';
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

        // SIM-1 residual (PM review 2026-10-27, B3): a member who
        // already earned a reward sees it on this page, the page
        // the product tells them to bookmark. Before this, the
        // claim URL existed only inside SMS jobs, so the most
        // valuable state in the product was invisible on its home
        // page. Vesting and the closure wind-down are applied on
        // this read, exactly as on the claim page, so the block
        // never shows a state the claim page would contradict.
        // The single most relevant reward is shown: an available
        // reward beats a vesting one, and within a state the
        // nearest unlock comes first.
        $earnedRewards = $membership->rewards()
            ->whereIn('state', [Reward::STATE_PENDING_VESTING, Reward::STATE_AVAILABLE])
            ->get()
            ->filter(function (Reward $reward) {
                $reward->applyVesting();
                $reward->applyClosureExpiry();
                // M-4: a just-expired reward must not appear as
                // ready here while the claim page calls it
                // expired (the SIM-1 coherence rule).
                $reward->applyTimeExpiry();

                return in_array($reward->state, [Reward::STATE_PENDING_VESTING, Reward::STATE_AVAILABLE], true);
            })
            ->sort(fn (Reward $a, Reward $b) => [
                $a->state === Reward::STATE_AVAILABLE ? 0 : 1,
                $a->available_at?->timestamp ?? PHP_INT_MAX,
                $a->id,
            ] <=> [
                $b->state === Reward::STATE_AVAILABLE ? 0 : 1,
                $b->available_at?->timestamp ?? PHP_INT_MAX,
                $b->id,
            ])
            ->values();
        $topReward = $earnedRewards->first();
        $rewardClaimUrl = $topReward !== null && $topReward->claim_token_encrypted
            ? route('refer.claim.page', ['token' => $topReward->claim_token_encrypted])
            : null;

        // SA-02 (Train B, Amendment 39): every additional live
        // reward is reachable from this page, not just counted.
        // Each row carries that reward's own claim URL, built
        // from its own sealed token exactly as the top block's
        // is; the collection order (ready first, then nearest
        // unlock) is the order the rows render in. Terminal
        // rewards never enter the collection (the query and the
        // on-read transitions above), so no row can present a
        // claimed, denied, or expired reward as held.
        $additionalRewards = $earnedRewards->skip(1)
            ->map(fn (Reward $reward) => [
                'reward' => $reward,
                'claimUrl' => $reward->claim_token_encrypted
                    ? route('refer.claim.page', ['token' => $reward->claim_token_encrypted])
                    : null,
            ])
            ->values()
            ->all();

        $shareUrl = route('refer.friend', ['token' => $membership->plainShareToken()]);
        $shareMessage = $business->friend_reward_text !== null
            ? $membership->customer->fullName().' invited you to get your Reward: '.$business->friend_reward_text.' at '.$business->name.'. Click here to claim the reward!'
            : $membership->customer->fullName().' invited you to '.$business->name.'. Click here to claim the reward!';

        // ID-08 [F2R-02] (idea-08-design-pass.md Sections 2.4, 4,
        // 8.3): the passive contagion reminder, for a member who
        // arrived at THIS business as a redeemed friend and has
        // not yet created a downstream referral here. A single
        // nullable payload so the template cannot half-render
        // it. Every conjunct is load-bearing: flow live at
        // render (a paused or closed business's invitation
        // would promise a reward behind a dead flow), member
        // not removed and share token active (S16), at least
        // one redeemed referral as the friend, zero referrals
        // created as referrer (the first downstream referral
        // retires the line for good, rule F2R-QUIET item 2),
        // and the provenance name (the referrer of the member's
        // MOST RECENT redeemed referral) resolves. The
        // downstream check reads referral ROWS, not the
        // referral_count column, so it is the same fact the
        // MEASURE query counts (Section 7.2).
        $friendToReferrerReminder = null;

        if ($this->flowIsLive($business)
            && $membership->removed_at === null
            && $membership->share_token_state === 'active') {
            $hasDownstreamReferral = Referral::query()
                ->where('referrer_membership_id', $membership->id)
                ->exists();

            if (! $hasDownstreamReferral) {
                $latestRedeemedAsFriend = Referral::query()
                    ->where('business_id', $business->id)
                    ->where('referred_customer_id', $membership->customer_id)
                    ->where('state', Referral::STATE_REDEEMED)
                    ->orderByDesc('redeemed_at')
                    ->orderByDesc('id')
                    ->first();
                $provenanceFirstName = $latestRedeemedAsFriend?->referrerMembership?->customer?->first_name;

                if ($provenanceFirstName !== null && trim((string) $provenanceFirstName) !== '') {
                    $friendToReferrerReminder = [
                        'referrerFirstName' => $provenanceFirstName,
                    ];
                }
            }
        }

        return response()->view('flow.share', [
            'business' => $business,
            'customer' => $membership->customer,
            'shareUrl' => $shareUrl,
            'shareMessage' => $shareMessage,
            'shareQrUrl' => route('refer.share.qr'),
            'referralCount' => (int) $membership->referral_count,
            'returning' => (bool) ($verified['returning'] ?? false),
            // OR-1 §2.3: the referrer's effective reward, from
            // the ONE resolver (OD-2's fallback, centralized):
            // SPLIT reads the referrer text, UNIFIED the reward.
            'referrerRewardText' => $business->effectiveReferrerRewardText(),
            'topReward' => $topReward,
            'rewardClaimUrl' => $rewardClaimUrl,
            'additionalRewards' => $additionalRewards,
            'friendToReferrerReminder' => $friendToReferrerReminder,
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
            return $this->message('not_active', $business);
        }

        [$flow, $newToken] = $this->beginFlow($request, $business);

        return $this->respond(response()->view('flow.friend', [
            'business' => $business,
            'referrer' => $membership->customer,
            'token' => $token,
            'phoneHelp' => $this->phoneConfirmationLine($request),
            'draft' => $this->draftFor($flow, $business),
            // RS-N1 (Train B): the OG preview payload, built only
            // here on the live path (the not_active return above
            // passes none, so a paused or closed business's link
            // unfurls with no reward promise). The sender's first
            // name rides in og:title only; the per-business image
            // carries no name at all.
            'og' => app(\App\Services\Share\OgCardRenderer::class)->metaFor($business, $membership->customer->first_name),
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
            return $this->message('not_active', $business);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
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

        // SELF-01 (owner-findings fix wave): the self-referral
        // rule is stated the second the number is entered, at
        // this step, not after the friend has verified. The
        // comparison is the completion backstop's own: both
        // sides are the usPhone() E.164 form, so punctuation
        // and formatting cannot slip past it. The completion
        // check in EnrollmentService stays; it is the fraud
        // backstop behind this early surface.
        if ($phone === $membership->customer->phone_e164) {
            return back()->withErrors(['phone' => 'You cannot refer yourself. Please share your link with a friend instead.'])->withInput();
        }

        // U9 (launch-readiness F-4): the business's-own-number
        // stop fires here too, at the form, not only at
        // completion after a code was sent to the business's
        // phone. The comparison is the completion backstop's own
        // (EnrollmentService::completeClaim): the entered E.164
        // against the business's users' phone numbers. The error
        // text is the flow/self owner_phone page's designed words
        // verbatim. The completion check stays as the backstop.
        if ($business->users()->where('phone_e164', $phone)->exists()) {
            return back()->withErrors(['phone' => "That is the business's own number. This number belongs to {$business->name}, so no reward can be earned with it. Please use the customer's own mobile number."])->withInput();
        }

        // U1 (launch-readiness F-1): the claim's consent panel is
        // the same agreement as the enrollment panel, and it gates
        // the same way (FIX-02): without it the claim does not
        // advance and no code is requested, because the code and
        // the reward texts both ride on this consent. This gate
        // sits after the SELF-01 refusal so a self-referral still
        // reads as a self-referral, and before startVerification
        // so no verification is ever started without consent.
        if (! $request->boolean('consent')) {
            return back()->withErrors(['consent' => 'Please agree to the texts so we can send your code and your reward updates.'])->withInput();
        }

        // BREAKER D-1 claim-step gate (2026-10-29): a pair whose
        // claim the owner already denied is terminal — the friend
        // is taken straight to that referral's own page (its
        // unavailable state) and no verification code is burned on
        // a claim that can never complete. The completion backstop
        // (EnrollmentService::completeClaim's governing-referral
        // resolution) stays authoritative. A REDEEMED pair is
        // deliberately NOT shortcut here: the visit page is a
        // bearer page, so it is only ever reached after the phone
        // verifies, where completeClaim resolves to it.
        $claimant = Customer::query()->where('phone_e164', $phone)->first();

        if ($claimant !== null) {
            $pairStates = Referral::query()
                ->where('business_id', $business->id)
                ->where('referred_customer_id', $claimant->id)
                ->pluck('state');

            if (! $pairStates->contains(Referral::STATE_REDEEMED) && ! $pairStates->contains(Referral::STATE_PENDING)) {
                $deniedReferral = Referral::query()
                    ->where('business_id', $business->id)
                    ->where('referred_customer_id', $claimant->id)
                    ->where('state', Referral::STATE_VOIDED)
                    ->whereNotNull('redeem_token_encrypted')
                    ->first();

                if ($deniedReferral !== null) {
                    return redirect()->route('refer.redeem.page', ['token' => $deniedReferral->redeem_token_encrypted]);
                }
            }
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
            'last_name' => $data['last_name'] ?? '',
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

        // The demo business's tokens (Amendment 63) live only
        // inside the demo guard; on the real surfaces they
        // answer exactly as a bad link does.
        if ($business->isDemo()) {
            return $this->resolutionFailure($request, null, 'bad_link', 'unknown_token');
        }

        // FE-CANCEL-01 (C-03): a referral voided BY THE ENDING
        // (its referral_voided event carries the account_closed
        // reason) gets the ended wording, distinct from a denied
        // or otherwise voided referral's generic unavailable
        // state, whose shipped wording stands.
        $voidedByEnding = $referral->state === Referral::STATE_VOIDED
            && \App\Models\ReferralEvent::query()
                ->where('referral_id', $referral->id)
                ->where('event_type', 'referral_voided')
                ->where('metadata->reason', 'account_closed')
                ->exists();

        $state = match (true) {
            $voidedByEnding => 'ended',
            $referral->state === Referral::STATE_REDEEMED => 'redeemed',
            $referral->state === Referral::STATE_EXPIRED => 'expired',
            // Option B (2026-10-28): a pending referral has no
            // friend-facing held state — converted flags hold the
            // referrer's reward at redemption, never this page.
            $referral->state === Referral::STATE_PENDING => 'show',
            default => 'unavailable',
        };

        $activeQr = $business->activeQrCode();

        // RS-N2 (Train B, Amendment 39): the contagion prime, on
        // the friend's REDEEMED receipt only. Every clause is
        // true in exactly that state: this person came in through
        // a friend, completed a visit, and holds their own share
        // link (minted at claim completion). The payload is a
        // single nullable array so the template cannot
        // half-render it, and it is built only when the flow is
        // still live: a business paused or closed after the visit
        // keeps its receipt but drops the invitation, whose link
        // would land on the not_active page. A redeemed referral
        // whose friend has no membership (minted outside the
        // claim flow) has no link to prime with and gets none.
        $prime = null;

        if ($state === 'redeemed' && $this->flowIsLive($business)) {
            $friendMembership = Membership::query()
                ->where('business_id', $business->id)
                ->where('customer_id', $referral->referred_customer_id)
                ->first();
            $friendShareToken = $friendMembership?->plainShareToken();
            $referrerFirstName = $referral->referrerMembership?->customer?->first_name;

            if ($friendMembership !== null && $friendShareToken !== null && $referrerFirstName !== null) {
                $friendHeadline = $business->friendRewardHeadline();
                // OR-1 §2.3: the referrer's effective reward from
                // the ONE resolver, as on the share page.
                $referrerReward = $business->effectiveReferrerRewardText();

                $prime = [
                    'referrerFirstName' => $referrerFirstName,
                    'shareUrl' => route('refer.friend', ['token' => $friendShareToken]),
                    'shareMessage' => $business->friend_reward_text !== null
                        ? ($referral->referredCustomer?->fullName() ?? 'A friend').' invited you to get your Reward: '.$business->friend_reward_text.' at '.$business->name.'. Click here to claim the reward!'
                        : ($referral->referredCustomer?->fullName() ?? 'A friend').' invited you to '.$business->name.'. Click here to claim the reward!',
                    // The friend side composes through
                    // friendRewardHeadline() so the first-visit
                    // phrase can never double up against a reward
                    // text that already carries it (the F-2 rule).
                    // OR-1 §1.3 B4 (ORS-04): a UNIFIED business
                    // primes with the one shared value — composed
                    // from the normalized reward text, NOT the
                    // friend headline, whose "on your first
                    // visit" suffix would be false for the
                    // referrer side of the "you both" claim. A
                    // SPLIT business keeps the shipped composed
                    // line byte-identically.
                    'offerLine' => $friendHeadline === null
                        ? 'Share your link with a friend, and you both get rewarded when they visit.'
                        : ($business->rewardsAreSplit()
                            ? 'Share your link with a friend. They get '.rtrim($friendHeadline, '.').', and you get '.rtrim(trim((string) $referrerReward), '.').' when they visit.'
                            : 'Share your link with a friend. You both get '.trim(rtrim(trim((string) $business->rewardText()), '.')).' when they visit.'),
                ];
            }
        }

        // ID-08 M1 (idea-08-design-pass.md Section 7.3): the
        // prime impression row. The shipped prime wrote nothing,
        // so a flat activation readout could not distinguish
        // "never seen" from "seen and ignored". Rule, exact:
        // on this render, state redeemed WITH a non-null prime
        // payload, write one prime_shown row in the existing
        // append-only referral_events log (no migration, no new
        // column; referral_id and membership_id are the log's
        // keys, the review_ask_shown pattern) if and only if no
        // prime_shown row already exists for this referral id.
        // Receipt re-views therefore record exactly once. This
        // is instrumentation only: it moves no money, changes
        // no reward state, and sends nothing, and a failure in
        // it must never break the receipt (the ID-05 discipline
        // below), so the write is guarded.
        if ($prime !== null) {
            try {
                $alreadyShown = \App\Models\ReferralEvent::query()
                    ->where('referral_id', $referral->id)
                    ->where('event_type', 'prime_shown')
                    ->exists();

                if (! $alreadyShown) {
                    \App\Models\ReferralEvent::record(
                        'prime_shown',
                        'customer',
                        $business,
                        $referral,
                        null,
                        $friendMembership ?? null,
                    );
                }
            } catch (\Throwable) {
                // Measurement defaults to absent; the receipt
                // renders exactly as it would have.
            }
        }

        // ID-05 (Amendment 48): the deliberate review ask, on
        // the redeemed receipt only, decided by the ONE
        // eligibility service. Holds and flow state are never
        // suppressors: this runs for every redeemed receipt,
        // prime or no prime, live flow or paused. A failure in
        // the ask machinery defaults the ask to ABSENT — a
        // broken ask must never break a receipt.
        $reviewAsk = false;
        if ($state === 'redeemed') {
            try {
                $friendMembership = Membership::query()
                    ->where('business_id', $business->id)
                    ->where('customer_id', $referral->referred_customer_id)
                    ->first();
                $decision = \App\Services\Flow\ReviewAsk::evaluate($business, $friendMembership, $referral);
                $reviewAsk = $decision['render'];
                if ($reviewAsk && $decision['record'] && $friendMembership !== null) {
                    \App\Services\Flow\ReviewAsk::recordShown($business, $friendMembership, $referral, 'redeem');
                }
            } catch (\Throwable) {
                $reviewAsk = false;
            }
        }

        return response()->view('flow.redeem', [
            'business' => $business,
            'referral' => $referral,
            'state' => $state,
            'prime' => $prime,
            'reviewAsk' => $reviewAsk,
            'reviewClickUrl' => route('refer.redeem.review', ['token' => $token]),
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

    /**
     * ID-05 (Amendment 48): the review ask's click route for
     * the redeem receipt. The tap is recorded once per receipt
     * (measurement second), then the friend is redirected to
     * the business's CURRENT Google review link — re-read live
     * at tap time, never a stored snapshot. If the record is
     * no longer completed or the link is gone, the tap falls
     * back to the receipt page: never an error page, never a
     * stale URL.
     */
    public function redeemReviewRedirect(Request $request, string $token): \Illuminate\Http\RedirectResponse
    {
        $referral = Referral::query()
            ->where('redeem_token_hash', OpaqueToken::hash($token))
            ->first();

        if ($referral === null || $referral->state !== Referral::STATE_REDEEMED) {
            return redirect()->route('refer.redeem.page', ['token' => $token]);
        }

        $business = $referral->business;
        $url = $business->google_review_url;

        if ($url === null || trim((string) $url) === '') {
            return redirect()->route('refer.redeem.page', ['token' => $token]);
        }

        try {
            $membership = Membership::query()
                ->where('business_id', $business->id)
                ->where('customer_id', $referral->referred_customer_id)
                ->first();
            \App\Services\Flow\ReviewAsk::recordClicked($business, $membership, $referral, 'redeem');
        } catch (\Throwable) {
            // The friend's tap is honoured first; measurement second.
        }

        return redirect()->away($url);
    }

    /**
     * ID-05: the click route for the claim receipt; same
     * contract as the redeem leg.
     */
    public function claimReviewRedirect(Request $request, string $token): \Illuminate\Http\RedirectResponse
    {
        $reward = Reward::query()
            ->where('claim_token_hash', OpaqueToken::hash($token))
            ->first();

        if ($reward === null || $reward->state !== Reward::STATE_CLAIMED) {
            return redirect()->route('refer.claim.page', ['token' => $token]);
        }

        $business = $reward->business;
        $url = $business->google_review_url;

        if ($url === null || trim((string) $url) === '') {
            return redirect()->route('refer.claim.page', ['token' => $token]);
        }

        try {
            \App\Services\Flow\ReviewAsk::recordClicked($business, $reward->membership, $reward, 'claim');
        } catch (\Throwable) {
            // The tap is honoured first; measurement second.
        }

        return redirect()->away($url);
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

        if ($business->isDemo()) {
            return $this->resolutionFailure($request, null, 'bad_link', 'unknown_token');
        }

        // The closure wind-down end (spec Section 5.5): once the
        // 30 days have run out the reward expires and its claim
        // link returns the same generic page as a bad link.
        $reward->applyClosureExpiry();
        // M-4: the reward's own 12-month date applies on this
        // read too. The two expiries answer differently (pass
        // Section 2.7): closure-expired (the business itself is
        // gone past its window) keeps the generic page below,
        // while a time-expired reward at a LIVING business falls
        // through to the named expired state, which owes the
        // customer the date it ran out. Two-key fix F3: a legacy
        // expired row with NO stamped date has no date to name,
        // so it takes the same generic page (mirroring the staff
        // guard from c1b4ebc) instead of formatting a null date
        // in the named branch and 500ing.
        $reward->applyTimeExpiry();

        if ($reward->state === Reward::STATE_EXPIRED
            && ($business->closureWindowEnded() || $reward->expires_at === null)) {
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
            // M-4 named expired state: reachable only for a
            // time-expired reward (closure-expired returned the
            // generic page above).
            $reward->state === Reward::STATE_EXPIRED => 'expired',
            $reward->hold => 'held',
            $reward->state === Reward::STATE_AVAILABLE => 'ready',
            default => 'waiting',
        };

        // ID-05: the same eligibility service on the claimed
        // receipt, sharing the (business, customer) cooldown
        // clock with the redeem surface.
        $reviewAsk = false;
        if ($state === 'claimed') {
            try {
                $decision = \App\Services\Flow\ReviewAsk::evaluate($business, $reward->membership, $reward);
                $reviewAsk = $decision['render'];
                if ($reviewAsk && $decision['record'] && $reward->membership !== null) {
                    \App\Services\Flow\ReviewAsk::recordShown($business, $reward->membership, $reward, 'claim');
                }
            } catch (\Throwable) {
                $reviewAsk = false;
            }
        }

        // ID-07 [RR-CP-01] (Amendment 51): the reshare block on
        // the READY state only, below the QR in the template.
        // A single nullable payload so the template cannot
        // half-render it. Gates mirror the SMS appendage's
        // RR-GATE in render form: the flow is live right now
        // (flowIsActive at render: a ready reward on a paused
        // business keeps its QR and loses the block) and the
        // membership's share link is live (not removed, token
        // active: TS3-12). No cap here: the page is pull-only,
        // rendered on the referrer's own visit to their own
        // page, and it is the only reshare surface for
        // opted-out referrers, who receive no text at all.
        $reshare = null;
        if ($state === 'ready' && $business->flowIsActive()) {
            $membership = $reward->membership;
            $shareToken = $membership?->plainShareToken();

            if ($membership !== null
                && $membership->removed_at === null
                && $membership->share_token_state === 'active'
                && $shareToken !== null
                && $shareToken !== '') {
                $reshare = [
                    // [F2R-03] for the came-as-a-friend cohort
                    // (ID-08 Section 7.2 provenance join), the
                    // block's first line when present.
                    'cameAsFriend' => Referral::query()
                        ->where('business_id', $business->id)
                        ->where('referred_customer_id', $membership->customer_id)
                        ->where('state', Referral::STATE_REDEEMED)
                        ->exists(),
                    'shareUrl' => route('refer.friend', ['token' => $shareToken]),
                    // Composed exactly as the share page composes
                    // its message (ReferController::share).
                    'shareMessage' => $business->friend_reward_text !== null
                        ? $membership->customer->fullName().' invited you to get your Reward: '.$business->friend_reward_text.' at '.$business->name.'. Click here to claim the reward!'
                        : $membership->customer->fullName().' invited you to '.$business->name.'. Click here to claim the reward!',
                ];
            }
        }

        return response()->view('flow.claim', [
            'business' => $business,
            'reward' => $reward,
            'state' => $state,
            'reviewAsk' => $reviewAsk,
            'reshare' => $reshare,
            'reviewClickUrl' => route('refer.claim.review', ['token' => $token]),
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
    private function beginFlow(Request $request, Business $business, ?QrCode $qr = null): array
    {
        $flow = $this->flows->fromRequest($request);

        if ($flow === null) {
            [$flow, $token] = $this->flows->start([
                'qr_business_id' => $business->id,
                'scan_business_id' => $business->id,
                // QRK-05: which placement's code brought the
                // customer here, for the scan event's metadata.
                // Friend-link arrivals pass no code and carry none.
                'qr_code_id' => $qr?->id,
            ]);

            return [$flow, $token];
        }

        if ((int) ($this->flows->get($flow, 'qr_business_id') ?? 0) !== $business->id) {
            $this->flows->put($flow, [
                'qr_business_id' => $business->id,
                'scan_business_id' => $business->id,
                'qr_code_id' => $qr?->id,
            ]);
        } elseif ($qr !== null) {
            // Same store, a different spot's code: the scan this
            // flow produces belongs to the code just scanned.
            $this->flows->put($flow, ['qr_code_id' => $qr->id]);
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
        // Activation redesign (census C-34; design pass
        // Section 4.3): the allowance pause gets its OWN friend
        // page. A not-active failure whose block reason is the
        // exhausted allowance renders 'paused_for_card' — the
        // business named, temporary by construction, and
        // already-claimed rewards stated still good at the
        // counter — and never the generic not-active page.
        // Every other cause keeps the shipped page. The branch
        // lives here, in the one message() path, so landing,
        // enroll, friend landing, and the claim endpoints can
        // never drift apart.
        if ($state === 'not_active' && $business !== null
            && $business->flowBlockReason() === 'allowance_exhausted') {
            $state = 'paused_for_card';
        }

        // The does-not-resolve family answers 404 (spec Section 3
        // Step 1, inventory REF-LAND-02): unknown and revoked codes
        // stay indistinguishable and absent. A resolved business
        // whose program is not active is different (FE-INACTIVE-01):
        // it gets the named not-active page, which still answers
        // 404. Past the failed-resolution budget the same body
        // answers 429 instead (Section 10.4). The allowance-paused
        // page keeps the same 404 status for resolution-guard
        // consistency (design pass Section 4.3).
        $status ??= in_array($state, ['inactive_qr', 'not_active', 'bad_link', 'paused_for_card'], true) ? 404 : 200;

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
        // A demo token's QR (Amendment 63) is served only by the
        // demo surface; here it is a 404 like any unknown token.
        $model = OpaqueToken::isWellFormed($token)
            ? ($kind === 'redeem'
                ? Referral::query()->where('redeem_token_hash', OpaqueToken::hash($token))->first()
                : Reward::query()->where('claim_token_hash', OpaqueToken::hash($token))->first())
            : null;

        $found = $model !== null && ! $model->business->isDemo();

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
