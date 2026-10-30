<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CouponClaim;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Referral\NonceService;
use App\Services\Referral\RedemptionService;
use App\Services\Security\TokenResolutionGuard;
use App\Support\OpaqueToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The staff scan-and-confirm screens R8a and R8b (spec Section
 * 6.1): whoever holds the phone completes the redemption. No PIN,
 * no account, no session is required (the v1 PIN is gone by owner
 * decision); an authenticated session from the same business
 * attributes the action and never gates it. Unknown, malformed,
 * expired-past-presentation, and voided tokens all render the same
 * generic state and feed the per-IP failed-resolution budget.
 */
class StaffRedemptionController extends Controller
{
    public function __construct(
        private RedemptionService $redemption,
        private NonceService $nonces,
        private TokenResolutionGuard $resolutions,
    ) {}

    public function show(Request $request, string $token): View|Response
    {
        $resolved = $this->resolve($token);

        if ($resolved === null) {
            return $this->rejectUnknown($request);
        }

        [$type, $model] = $resolved;

        return match ($type) {
            'referral' => $this->showReferral($request, $model, $token),
            'coupon' => $this->showCoupon($request, $model, $token),
            default => $this->showReward($request, $model, $token),
        };
    }

    public function confirm(Request $request, string $token): RedirectResponse|View|Response
    {
        $resolved = $this->resolve($token);

        if ($resolved === null) {
            return $this->rejectUnknown($request);
        }

        [$type, $model] = $resolved;
        $subjectType = $type;

        // The per-render nonce: any POST consumes it, so a failed
        // attempt forces a fresh render with a fresh nonce.
        if (! $this->nonces->consume($subjectType, $model->id, $request->input('nonce'))) {
            // The double-submit race loser (T5-F1): the nonce is
            // spent because the winning press, already in flight
            // from another tab of this session, completed this
            // token. That is not an expired screen, and its
            // landing is the same already-done state a fresh scan
            // shows, so the winner's success flash (shared by the
            // tabs' one session) must not leak into it either.
            if ($this->alreadyCompleted($type, $model)) {
                $this->forgetSuccessFlash($request, $token);

                return redirect()->route('redeem.show', ['token' => $token]);
            }

            return redirect()
                ->route('redeem.show', ['token' => $token])
                ->with('staff_error', 'For security, this screen had expired. Here is a fresh one.');
        }

        // Perform the transition, then land on the show screen. A
        // transition this press actually completed flashes the
        // Section 3.5 success result (R8b) for exactly that one
        // landing; any later scan of the same code renders the
        // resume state ("Already redeemed" / "Already claimed"),
        // which describes a duplicate presentation, never the
        // completion itself (F-02).
        if ($type === 'coupon') {
            // ID-11: the coupon sibling transition. It creates
            // no billing event and mints no reward (pass
            // Sections 3.2 and 5.1): the claim flips, the audit
            // event records it, and that is the whole write set.
            $result = app(\App\Services\Coupons\CouponService::class)->redeemClaim($model, $request->user(), $request->ip());
            $completed = ($result['outcome'] ?? null) === 'redeemed';
            $rewardText = $model->offer_text;
            $confirmedAt = $model->fresh()?->redeemed_at;
        } elseif ($type === 'referral') {
            $result = $this->redemption->redeemReferral($model, $request->user(), $request->ip());
            $completed = ($result['outcome'] ?? null) === 'redeemed';

            // [TWO-KEY HUNK — redemption block] The gap guard: a
            // press inside the 25-minute window lands on the
            // blocked screen with the one composed block string
            // as staff_error — never a success flash, never a
            // silent redirect, never a resolution failure.
            if (($result['outcome'] ?? null) === 'too_early') {
                $this->forgetSuccessFlash($request, $token);

                return redirect()
                    ->route('redeem.show', ['token' => $token])
                    ->with('staff_error', self::gapBlockedMessage($model->business, $result['unlock_at']));
            }

            // Train F2 backstop: a press that races an un-set of
            // the reward texts lands on the designed refusal
            // (Amendment 40, F2-RDM-01), never a crash and never
            // a success flash — the visit stays saved and pending.
            if (($result['outcome'] ?? null) === 'missing_reward_text') {
                $this->forgetSuccessFlash($request, $token);

                return redirect()
                    ->route('redeem.show', ['token' => $token])
                    ->with('staff_error', self::missingRewardTextMessage($model->business));
            }
            // TWO-KEY FLAG (F-UP-1): reward-truth hunk. The
            // success flood renders the referral's claim-time
            // snapshot (promisedFriendRewardText(), with its
            // named LEGACY LIVE-CONFIG FALLBACK), not live
            // config: the flood is the record of the visit that
            // just completed.
            $rewardText = $model->fresh()?->promisedFriendRewardText() ?? $model->business->friend_reward_text;
            $confirmedAt = $model->fresh()?->redeemed_at;
        } else {
            $result = $this->redemption->claimReward($model, $request->user(), $request->ip());
            $completed = ($result['outcome'] ?? null) === 'claimed';
            $rewardText = $model->reward_text_snapshot;
            $confirmedAt = $model->fresh()?->claimed_at;
        }

        if (! $completed) {
            // A press that finds the token already done (a valid
            // nonce rendered before the winner completed) lands on
            // the already-done state too (T5-F1): no stale success
            // flash from the winning press may dress it up as this
            // press's own completion.
            $this->forgetSuccessFlash($request, $token);
        }

        $redirect = redirect()->route('redeem.show', ['token' => $token]);

        if ($completed && $confirmedAt !== null) {
            $redirect->with('staff_success', [
                'token' => $token,
                'reward_text' => $rewardText,
                'business_name' => $model->business->name,
                'business_id' => $model->business_id,
                // R4-U4: the receipt's outcome word, distinct per
                // flow, so the staff flood matches the customer's.
                'outcome' => match ($type) {
                    'referral' => 'Reward redeemed',
                    'coupon' => 'Coupon redeemed',
                    default => 'Reward claimed',
                },
                'confirmed_at' => $confirmedAt->toIso8601String(),
            ]);
        }

        return $redirect;
    }

    /**
     * Whether the token's transition already happened (the
     * double-submit race's winning press, T5-F1).
     */
    private function alreadyCompleted(string $type, Referral|Reward|CouponClaim $model): bool
    {
        $fresh = $model->fresh();

        return match ($type) {
            'referral' => $fresh?->state === Referral::STATE_REDEEMED,
            'coupon' => $fresh?->state === CouponClaim::STATE_REDEEMED,
            default => $fresh?->state === Reward::STATE_CLAIMED,
        };
    }

    /**
     * Drop this token's pending success flash, if one is in the
     * session: in a shared-session race the flash belongs to the
     * winning press, and the losing press's landing must render
     * the already-done state, not the winner's flood (T5-F1).
     */
    private function forgetSuccessFlash(Request $request, string $token): void
    {
        $success = $request->session()->get('staff_success');

        if (is_array($success) && ($success['token'] ?? null) === $token) {
            $request->session()->forget('staff_success');
        }
    }

    /**
     * The success result flashed by this session's own confirm
     * press for this token, if that is the landing being rendered.
     *
     * @return array<string, string>|null
     */
    private function justCompleted(Request $request, string $token): ?array
    {
        $success = $request->session()->get('staff_success');

        return is_array($success) && ($success['token'] ?? null) === $token ? $success : null;
    }

    private function successView(array $success): View
    {
        // R4-U4: resolve the business so the shared receipt
        // partial can render its timestamp in business-local
        // time (Business::localTime, Train 3.5), exactly as the
        // customer's own receipt does.
        $business = isset($success['business_id'])
            ? Business::query()->find($success['business_id'])
            : null;

        return view('staff.success', [
            'rewardText' => $success['reward_text'] ?? 'Reward',
            'businessName' => $business?->name ?? $success['business_name'],
            'business' => $business,
            'outcome' => $success['outcome'] ?? null,
            'confirmedAt' => $success['confirmed_at'],
        ]);
    }

    private function showReferral(Request $request, Referral $referral, string $token): View|Response
    {
        $referral->applyExpiry();
        $business = $referral->business;

        if ($referral->state === Referral::STATE_VOIDED) {
            return $this->rejectTerminal($request, $business, $referral, null, 'voided_token');
        }

        if ($referral->state === Referral::STATE_REDEEMED) {
            $success = $this->justCompleted($request, $token);

            if ($success !== null) {
                return $this->successView($success);
            }

            return view('staff.state', [
                'state' => 'already_redeemed',
                'business' => $business,
                // U2: state-page dates render in business-local
                // time, localized here at the source so every
                // format in the template reads the local day.
                'date' => $business->localTime($referral->redeemed_at),
                'friendName' => $referral->referredCustomer->first_name,
                // TWO-KEY FLAG (F-UP-1): reward-truth hunk. The
                // already-redeemed record renders the claim-time
                // snapshot (named LEGACY LIVE-CONFIG FALLBACK
                // when null), not live config.
                'friendRewardText' => $referral->promisedFriendRewardText(),
            ]);
        }

        if ($referral->state === Referral::STATE_EXPIRED) {
            // An expired presentation is a rejected presentation
            // (Section 6.1 item 5): recorded, and past the shared
            // budget the generic state answers with 429 instead.
            if ($this->resolutions->recordFailure($business, $referral, null, $referral->referrerMembership, 'expired_token', $request->ip())) {
                return response($this->genericState()->render(), 429);
            }

            // TWO-KEY FLAG (F-UP-1): reward-truth hunk. The
            // expired state renders the claim-time snapshot
            // (named LEGACY LIVE-CONFIG FALLBACK when null):
            // the lapsed offer is the one the friend claimed,
            // not a later edit.
            return view('staff.state', ['state' => 'expired', 'business' => $business, 'date' => $business->localTime($referral->expires_at), 'friendRewardText' => $referral->promisedFriendRewardText()]);
        }

        // Option B (2026-10-28): a referral token has no staff
        // held state — a flagged pending referral falls through
        // to the confirm screen below and completes at the
        // counter; only its minted reward is held for review.
        // (The staff held state survives for REWARD tokens, in
        // showReward.)

        // [TWO-KEY HUNK — redemption block, show mirror] The
        // 25-minute gap, show leg (owner ruling 2026-10-29):
        // inside the window the screen names the block BEFORE
        // any press exists — band 'not_yet' with the opening
        // time, the one block string in the error slot, NO
        // confirm form and NO nonce. One audit event per
        // blocked presentation; no resolution failure is
        // recorded, so an honest retry can never 429. The gap
        // renders before the F2 block: its remedy (time) needs
        // nobody, while the F2 remedy needs the owner.
        $gapUnlockAt = $this->redemption->gapUnlockAt($referral);

        if ($gapUnlockAt !== null) {
            $this->redemption->recordGapBlock($referral, $request->user(), $request->ip(), $gapUnlockAt);
            $unlockLocal = $business->localTime($gapUnlockAt);

            return view('staff.referral', [
                'business' => $business,
                'referral' => $referral,
                'friend' => $referral->referredCustomer,
                'nonce' => null,
                'token' => $token,
                'blockedMessage' => self::gapBlockedMessage($business, $gapUnlockAt),
                'bandState' => 'not_yet',
                'bandDetail' => 'Opens '.$unlockLocal->format('g:i A'),
                'gapBlocked' => true,
            ]);
        }

        // Train F2 backstop, show leg: while the business has
        // no reward text to snapshot, the confirm screen names
        // the block BEFORE the press (Amendment 40, F2-RDM-01)
        // and renders no confirm form or nonce — there is no
        // press to make. The referral stays pending; once the
        // owner sets a reward, this same screen confirms
        // normally. (Minted-reward claims never pass through
        // here: showReward reads the stored snapshot and is
        // deliberately independent of the current texts.)
        // OR-1 §1.3 E3 re-key (named build trap): the blocked
        // check keys on the RESOLVER, not the referrer column —
        // under canonical UNIFIED storage that column is NULL
        // for every unified business, so a column-keyed check
        // would be permanently half-true and correct only by
        // accident of its other leg.
        $blocked = trim((string) ($business->effectiveReferrerRewardText() ?? '')) === '';

        return view('staff.referral', [
            'business' => $business,
            'referral' => $referral,
            'friend' => $referral->referredCustomer,
            'nonce' => $blocked ? null : $this->nonces->issue('referral', $referral->id),
            'token' => $token,
            'blockedMessage' => $blocked ? self::missingRewardTextMessage($business) : null,
        ]);
    }

    /**
     * Amendment 40, F2-RDM-01: the staff answer when a visit
     * cannot be finished because the business never set its
     * reward. Valid only while the referral is pending and both
     * texts are blank; "still saved" is true exactly then.
     */
    private static function missingRewardTextMessage(Business $business): string
    {
        return "This visit cannot be finished yet. {$business->name} has not set its reward. The visit is still saved. Ask the owner to set the reward in Settings, then scan again.";
    }

    /**
     * [GAP-RDM-01] The staff gap block: rule and time only, no
     * accusation. One composed string, two delivery slots (the
     * show leg's error slot and the confirm guard's staff_error
     * flash), never two copies. Valid ONLY for a pending
     * referral inside its own referrer anchor's window.
     */
    private static function gapBlockedMessage(Business $business, \Illuminate\Support\Carbon $unlockAt): string
    {
        $time = $business->localTime($unlockAt)->format('g:i A');

        return "This reward opens at {$time}. The visit is still saved, and this same code will work after that time.";
    }

    /**
     * ID-11: the staff view of a coupon claim. The delta from
     * the referral screen is exactly one line (COUP-26: the
     * source line naming the batch), plus the claim's own
     * terminal truths. A ready claim renders its confirm screen
     * in every business state: pause, card removal, batch kill,
     * and batch expiry never gate honouring it (pass Section
     * 2.4); only the claim's own expiry, applied on this read,
     * does.
     */
    private function showCoupon(Request $request, CouponClaim $claim, string $token): View|Response
    {
        $claim->applyExpiry();
        $business = $claim->business;

        if ($claim->state === CouponClaim::STATE_REDEEMED) {
            $success = $this->justCompleted($request, $token);

            if ($success !== null) {
                return $this->successView($success);
            }

            return view('staff.state', [
                'state' => 'coupon_already_redeemed',
                'business' => $business,
                'date' => $business->localTime($claim->redeemed_at),
                'offerText' => $claim->offer_text,
            ]);
        }

        if ($claim->state === CouponClaim::STATE_EXPIRED) {
            if ($this->resolutions->recordFailure($business, null, null, null, 'expired_token', $request->ip())) {
                return response($this->genericState()->render(), 429);
            }

            return view('staff.state', [
                'state' => 'coupon_expired',
                'business' => $business,
                'date' => $business->localTime($claim->expires_at),
                'offerText' => $claim->offer_text,
            ]);
        }

        if ($claim->state === CouponClaim::STATE_VOIDED) {
            return view('staff.state', [
                'state' => 'coupon_voided',
                'business' => $business,
                'date' => $business->localTime($claim->updated_at),
                'offerText' => $claim->offer_text,
            ]);
        }

        return view('staff.coupon', [
            'business' => $business,
            'claim' => $claim,
            'batch' => $claim->batch,
            'nonce' => $this->nonces->issue('coupon', $claim->id),
            'token' => $token,
        ]);
    }

    private function showReward(Request $request, Reward $reward, string $token): View|Response
    {
        $reward->applyVesting();
        // Past the closure wind-down the reward expires on read
        // and the expired branch below answers generically (spec
        // Section 5.5, RDM-29).
        $reward->applyClosureExpiry();
        // M-4: the reward's own date applies on this read too,
        // and the two expiries answer differently below.
        $reward->applyTimeExpiry();
        $business = $reward->business;

        if ($reward->state === Reward::STATE_DENIED) {
            return $this->rejectTerminal($request, $business, null, $reward, 'denied_token');
        }

        if ($reward->state === Reward::STATE_EXPIRED) {
            // M-4 (pass Section 2.7): closure keeps the generic
            // rejectTerminal; a TIME-expired reward at a living
            // business renders the named staff expired state
            // with its date, because staff are standing in front
            // of the customer and need the sentence. The closure
            // condition is checked first, per the pass. A legacy
            // expired row with no stamped date has no date to
            // name and keeps the generic answer.
            if (! $business->closureWindowEnded() && $reward->expires_at !== null) {
                return view('staff.state', [
                    'state' => 'expired',
                    'business' => $business,
                    'date' => $business->localTime($reward->expires_at),
                    'friendRewardText' => $reward->reward_text_snapshot,
                ]);
            }

            return $this->rejectTerminal($request, $business, null, $reward, 'expired_token');
        }

        if ($reward->state === Reward::STATE_CLAIMED) {
            $success = $this->justCompleted($request, $token);

            if ($success !== null) {
                return $this->successView($success);
            }

            return view('staff.state', [
                'state' => 'already_claimed',
                'business' => $business,
                'date' => $business->localTime($reward->claimed_at),
                'friendName' => $reward->membership->customer->first_name,
                'rewardText' => $reward->reward_text_snapshot,
            ]);
        }

        if ($reward->hold) {
            return view('staff.state', ['state' => 'held', 'business' => $business, 'rewardText' => $reward->reward_text_snapshot]);
        }

        if ($reward->state === Reward::STATE_PENDING_VESTING) {
            // The staff view of an unvested claim shows the date
            // (Section 6.1 item 7): staff need it to answer the
            // customer standing in front of them.
            return view('staff.state', [
                'state' => 'not_yet',
                'business' => $business,
                'date' => $business->localTime($reward->available_at),
                'rewardText' => $reward->reward_text_snapshot,
            ]);
        }

        return view('staff.reward', [
            'business' => $business,
            'reward' => $reward,
            'customer' => $reward->membership->customer,
            'nonce' => $this->nonces->issue('reward', $reward->id),
            'token' => $token,
        ]);
    }

    /**
     * @return array{0: string, 1: Referral|Reward|CouponClaim}|null
     */
    private function resolve(string $token): ?array
    {
        if (! OpaqueToken::isWellFormed($token)) {
            return null;
        }

        $hash = OpaqueToken::hash($token);

        $referral = Referral::query()->where('redeem_token_hash', $hash)->first();

        if ($referral !== null) {
            // G6 demo pin (Amendment 63, Section 6.2): a demo
            // token is redeemable ONLY inside the demo guard.
            // On this surface it answers exactly as an unknown
            // token does, so the demo business behaves here as
            // a business that can never be visited.
            return $referral->business->isDemo() ? null : ['referral', $referral];
        }

        $reward = Reward::query()->where('claim_token_hash', $hash)->first();

        if ($reward !== null) {
            return $reward->business->isDemo() ? null : ['reward', $reward];
        }

        $couponClaim = CouponClaim::query()->where('redeem_token_hash', $hash)->first();

        if ($couponClaim !== null) {
            // Same demo guard as the other two token families:
            // the demo business can never be visited here.
            return $couponClaim->business->isDemo() ? null : ['coupon', $couponClaim];
        }

        return null;
    }

    private function rejectUnknown(Request $request): View|Response
    {
        $exceeded = $this->resolutions->recordFailure(null, null, null, null, 'unknown_token', $request->ip());

        $response = $this->genericState();

        // The canonical R0 answer (F-13): the same generic body the
        // customer surface returns, with a real 404, matching it.
        // Past the budget the same body answers with 429 instead:
        // the status differs, the information does not (RDM-17).
        if ($exceeded) {
            return response($response->render(), 429);
        }

        return response($response->render(), 404);
    }

    /**
     * A resolved but terminal token (voided, denied, expired): the
     * presentation is recorded against its business (so the
     * owner's review queue can list it, Section 5.6) and counts
     * against the same shared budget.
     */
    private function rejectTerminal(Request $request, $business, ?Referral $referral, ?Reward $reward, string $reason): View|Response
    {
        $exceeded = $this->resolutions->recordFailure(
            $business, $referral, $reward, $referral?->referrerMembership ?? $reward?->membership, $reason, $request->ip(),
        );

        $response = $this->genericState();

        if ($exceeded) {
            return response($response->render(), 429);
        }

        return response($response->render(), 404);
    }

    private function genericState(): View
    {
        return view('staff.state', ['state' => 'generic', 'business' => null]);
    }
}
