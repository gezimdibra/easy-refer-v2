<?php

namespace App\Http\Controllers;

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

        return $type === 'referral'
            ? $this->showReferral($request, $model, $token)
            : $this->showReward($request, $model, $token);
    }

    public function confirm(Request $request, string $token): RedirectResponse|View|Response
    {
        $resolved = $this->resolve($token);

        if ($resolved === null) {
            return $this->rejectUnknown($request);
        }

        [$type, $model] = $resolved;
        $subjectType = $type === 'referral' ? 'referral' : 'reward';

        // The per-render nonce: any POST consumes it, so a failed
        // attempt forces a fresh render with a fresh nonce.
        if (! $this->nonces->consume($subjectType, $model->id, $request->input('nonce'))) {
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
        if ($type === 'referral') {
            $result = $this->redemption->redeemReferral($model, $request->user(), $request->ip());
            $completed = ($result['outcome'] ?? null) === 'redeemed';
            $rewardText = $model->business->friend_reward_text;
            $confirmedAt = $model->fresh()?->redeemed_at;
        } else {
            $result = $this->redemption->claimReward($model, $request->user(), $request->ip());
            $completed = ($result['outcome'] ?? null) === 'claimed';
            $rewardText = $model->reward_text_snapshot;
            $confirmedAt = $model->fresh()?->claimed_at;
        }

        $redirect = redirect()->route('redeem.show', ['token' => $token]);

        if ($completed && $confirmedAt !== null) {
            $redirect->with('staff_success', [
                'token' => $token,
                'reward_text' => $rewardText,
                'business_name' => $model->business->name,
                'confirmed_at' => $confirmedAt->toIso8601String(),
            ]);
        }

        return $redirect;
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
        return view('staff.success', [
            'rewardText' => $success['reward_text'] ?? 'Reward',
            'businessName' => $success['business_name'],
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
                'date' => $referral->redeemed_at,
                'friendName' => $referral->referredCustomer->first_name,
            ]);
        }

        if ($referral->state === Referral::STATE_EXPIRED) {
            // An expired presentation is a rejected presentation
            // (Section 6.1 item 5): recorded, and past the shared
            // budget the generic state answers with 429 instead.
            if ($this->resolutions->recordFailure($business, $referral, null, $referral->referrerMembership, 'expired_token', $request->ip())) {
                return response($this->genericState()->render(), 429);
            }

            return view('staff.state', ['state' => 'expired', 'business' => $business, 'date' => $referral->expires_at]);
        }

        if ($referral->hold) {
            return view('staff.state', ['state' => 'held', 'business' => $business]);
        }

        return view('staff.referral', [
            'business' => $business,
            'referral' => $referral,
            'friend' => $referral->referredCustomer,
            'nonce' => $this->nonces->issue('referral', $referral->id),
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
        $business = $reward->business;

        if ($reward->state === Reward::STATE_DENIED) {
            return $this->rejectTerminal($request, $business, null, $reward, 'denied_token');
        }

        if ($reward->state === Reward::STATE_EXPIRED) {
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
                'date' => $reward->claimed_at,
                'friendName' => $reward->membership->customer->first_name,
            ]);
        }

        if ($reward->hold) {
            return view('staff.state', ['state' => 'held', 'business' => $business]);
        }

        if ($reward->state === Reward::STATE_PENDING_VESTING) {
            // The staff view of an unvested claim shows the date
            // (Section 6.1 item 7): staff need it to answer the
            // customer standing in front of them.
            return view('staff.state', [
                'state' => 'not_yet',
                'business' => $business,
                'date' => $reward->available_at,
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
     * @return array{0: string, 1: Referral|Reward}|null
     */
    private function resolve(string $token): ?array
    {
        if (! OpaqueToken::isWellFormed($token)) {
            return null;
        }

        $hash = OpaqueToken::hash($token);

        $referral = Referral::query()->where('redeem_token_hash', $hash)->first();

        if ($referral !== null) {
            return ['referral', $referral];
        }

        $reward = Reward::query()->where('claim_token_hash', $hash)->first();

        if ($reward !== null) {
            return ['reward', $reward];
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
