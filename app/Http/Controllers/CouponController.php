<?php

namespace App\Http\Controllers;

use App\Models\CouponBatch;
use App\Models\CouponClaim;
use App\Models\Customer;
use App\Services\Coupons\CouponService;
use App\Services\Flow\FlowSessionManager;
use App\Services\Referral\VerificationService;
use App\Services\Security\TurnstileVerifier;
use App\Support\OpaqueToken;
use App\Support\PhoneNumber;
use App\Support\QrImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public coupon flow (ID-11, S3; design pass Section 2.2):
 * scan, verify, unlock, show at the counter. The definitional
 * difference from the referral flow is what is ABSENT: no
 * earning step, no referrer, no membership, no share link, and
 * the unlock is immediate at verified scan. Verification itself
 * is the shipped machinery: the same budgets, the same code
 * screen (pending kind "coupon"), the same flow session.
 */
class CouponController extends Controller
{
    public function __construct(
        private FlowSessionManager $flows,
        private VerificationService $verification,
        private CouponService $coupons,
        private TurnstileVerifier $turnstile,
    ) {}

    public function landing(Request $request, string $token): Response
    {
        $batch = CouponBatch::byToken($token);

        if ($batch === null) {
            return response()->view('flow.message', [
                'state' => 'inactive_qr',
                'business' => null,
                'returnUrl' => null,
                'waitMinutes' => (int) config('referrals.verify.code_ttl_minutes'),
            ], 404);
        }

        $business = $batch->business;
        $gate = $this->landingGate($batch);

        if ($gate !== null) {
            return response()->view('coupon.closed', [
                'business' => $business,
                'form' => $gate,
                'batch' => $batch,
            ]);
        }

        $this->coupons->recordScan($batch, $request->ip());

        return response()->view('coupon.landing', [
            'business' => $business,
            'batch' => $batch,
            'token' => $token,
            'draft' => [],
        ]);
    }

    public function start(Request $request, string $token): Response|RedirectResponse
    {
        $batch = CouponBatch::byToken($token);

        if ($batch === null) {
            abort(404);
        }

        $business = $batch->business;
        $gate = $this->landingGate($batch);

        if ($gate !== null) {
            return response()->view('coupon.closed', [
                'business' => $business,
                'form' => $gate,
                'batch' => $batch,
            ]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'consent' => ['nullable'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ], [
            'first_name.required' => 'Enter your first name.',
            'last_name.required' => 'Enter your last name.',
            'phone.required' => 'Enter your mobile phone number.',
        ]);

        if (! $this->turnstile->passes($request->input('cf-turnstile-response'), (string) $request->ip())) {
            return back()->withErrors(['turnstile' => 'We could not confirm this submission. Please try again.'])->withInput();
        }

        $phone = $this->usPhone((string) $data['phone']);

        if ($phone === null) {
            return back()->withErrors(['phone' => 'Enter a valid US phone number.'])->withInput();
        }

        if (! $request->boolean('consent')) {
            return back()->withErrors(['consent' => 'Please agree to the texts so we can send your code.'])->withInput();
        }

        // The limit pre-check (pass Section 4.2): a phone that
        // already holds this business's coupon lands on the
        // limit page WITHOUT a verification being started, so a
        // limited phone spends no verification cost.
        $customer = Customer::query()->where('phone_e164', $phone)->first();

        if ($customer !== null) {
            $limit = $this->coupons->limitFor($batch, $customer);

            if ($limit !== null) {
                $limitClaim = $limit['claim'];
                $limitClaim->applyExpiry();

                return response()->view('coupon.limit', [
                    'business' => $business,
                    'claim' => $limitClaim,
                    'claimUrl' => $limitClaim->state === CouponClaim::STATE_READY && $limitClaim->plainToken() !== null
                        ? route('coupon.claim.page', ['token' => $limitClaim->plainToken()])
                        : null,
                ]);
            }
        }

        $flow = $this->flows->fromRequest($request);
        $newToken = null;

        if ($flow === null) {
            [$flow, $newToken] = $this->flows->start([]);
        }

        $this->flows->forget($flow, 'verified');
        $this->flows->put($flow, [
            'qr_business_id' => $business->id,
            'draft' => [
                'business_id' => $business->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => PhoneNumber::formatForDisplay($phone),
                'email' => '',
                'consent' => true,
            ],
        ]);

        if ((int) ($this->flows->get($flow, 'flow_starts') ?? 0) >= (int) config('referrals.verify.starts_per_flow_session')) {
            return $this->messagePage('rate_limited', $business, route('coupon.landing', ['token' => $token]));
        }

        $result = $this->verification->start($phone, (string) $request->ip(), $business);
        $channel = 'sms';

        if ($result['outcome'] === 'landline_blocked') {
            $result = $this->verification->start($phone, (string) $request->ip(), $business, 'call');
            $channel = 'call';

            if ($result['outcome'] === 'landline_blocked') {
                return back()->withErrors(['phone' => 'That number cannot receive text messages. Please use a mobile number.'])->withInput();
            }
        }

        if ($result['outcome'] === 'rate_limited') {
            return $this->messagePage('rate_limited', $business, route('coupon.landing', ['token' => $token]));
        }

        if ($result['outcome'] !== 'started') {
            return $this->messagePage('provider_error', $business, route('coupon.landing', ['token' => $token]));
        }

        $this->flows->forget($flow, 'draft');
        $this->flows->put($flow, [
            'flow_starts' => (int) ($this->flows->get($flow, 'flow_starts') ?? 0) + 1,
            'pending' => [
                'kind' => 'coupon',
                'business_id' => $business->id,
                'batch_id' => $batch->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone_e164' => $phone,
                'email' => null,
                'consent' => true,
                'channel' => $channel,
                'checks' => 0,
                'starts' => 1,
                'last_start_at' => now()->timestamp,
                'return' => ['route' => 'coupon.landing', 'params' => ['token' => $token]],
            ],
        ]);

        $response = redirect()->route('refer.verify');

        if ($newToken !== null) {
            $response->withCookie($this->flows->cookie($newToken));
        }

        return $response;
    }

    public function claimPage(Request $request, string $token): Response
    {
        $claim = CouponClaim::byToken($token);

        if ($claim === null) {
            return response()->view('flow.message', [
                'state' => 'bad_link',
                'business' => null,
                'returnUrl' => null,
                'waitMinutes' => (int) config('referrals.verify.code_ttl_minutes'),
            ], 404);
        }

        $claim->applyExpiry();
        $business = $claim->business;

        if ($claim->state === CouponClaim::STATE_READY) {
            return response()->view('coupon.ready', [
                'business' => $business,
                'claim' => $claim,
                'batch' => $claim->batch,
                'qrUrl' => route('coupon.claim.qr', ['token' => $token]),
                'claimUrl' => route('coupon.claim.page', ['token' => $token]),
            ]);
        }

        return response()->view('coupon.claim-state', [
            'business' => $business,
            'claim' => $claim,
        ]);
    }

    public function claimQr(Request $request, string $token): Response
    {
        $found = OpaqueToken::isWellFormed($token)
            && CouponClaim::query()->where('redeem_token_hash', OpaqueToken::hash($token))->exists();

        if (! $found) {
            abort(404);
        }

        // The claim QR encodes the one staff endpoint, exactly
        // like the referral and reward QRs (spec Section 6.1).
        return response(QrImage::svg(route('redeem.show', ['token' => $token])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    /**
     * The landing's gate for NEW unlocks (pass Section 2.2,
     * states C6, C7, C8), read in order: batch state first (the
     * batch's own truth), then the business's coupon activation
     * picture. Null when the landing may render its form.
     */
    private function landingGate(CouponBatch $batch): ?string
    {
        $status = $batch->effectiveStatus();

        if ($status === CouponBatch::STATUS_FULL) {
            return 'full';
        }

        if ($status === CouponBatch::STATUS_KILLED || $status === CouponBatch::STATUS_EXPIRED) {
            return 'ended';
        }

        if (! $batch->business->couponFlowActive()) {
            return 'paused';
        }

        return null;
    }

    private function messagePage(string $state, $business, ?string $returnUrl): Response
    {
        return response()->view('flow.message', [
            'state' => $state,
            'business' => $business,
            'returnUrl' => $returnUrl,
            'waitMinutes' => (int) config('referrals.verify.code_ttl_minutes'),
        ]);
    }

    /** The flow's US-only phone rule (EC-21), as shipped. */
    private function usPhone(string $raw): ?string
    {
        $e164 = PhoneNumber::normalizeToE164($raw);

        if ($e164 === null || ! str_starts_with($e164, '+1') || strlen($e164) !== 12) {
            return null;
        }

        if ($e164[2] < '2' || $e164[5] < '2') {
            return null;
        }

        return $e164;
    }
}
