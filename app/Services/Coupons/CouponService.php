<?php

namespace App\Services\Coupons;

use App\Models\Business;
use App\Models\CouponBatch;
use App\Models\CouponClaim;
use App\Models\Customer;
use App\Models\ReferralEvent;
use App\Models\User;
use App\Support\OpaqueToken;
use App\Support\StarterPacks;
use Illuminate\Support\Facades\DB;

/**
 * The coupon channel's write paths (ID-11, S3; design pass
 * Sections 2, 4, 5, 8). Three rules run through every method:
 *
 * 1. NO BILLING, EVER. No method here creates a BillingEvent,
 *    touches free_visits_remaining, or reads the $99 cap: a
 *    coupon claim never bills (pass Section 3.2, ruled).
 * 2. NO REFERRAL MACHINERY. No Referral row, no membership, no
 *    share link, no Reward row: the claimer gains a Customer
 *    identity keyed by verified phone and nothing else.
 * 3. NO HOLDS. A claim is ready, redeemed, expired, or voided
 *    by its owner; anomaly review watches BATCHES (Section
 *    4.4), never people.
 */
class CouponService
{
    /**
     * The offer prefill for a new batch (pass Section 6.3,
     * finding FC-1): the owner APPROVES rather than authors.
     * Source order: (1) the business's own friend reward text,
     * (2) the starter pack offer for its trade, (3) the
     * platform default. The source rides along so the form's
     * helper sentence can name it truthfully (COUP-05 forms).
     *
     * @return array{text: string, source: 'owner'|'pack'|'default'}
     */
    public function prefillOffer(Business $business): array
    {
        $own = trim((string) $business->friend_reward_text);

        if ($own !== '') {
            return ['text' => $own, 'source' => 'owner'];
        }

        $pack = StarterPacks::starterFor($business->business_type)['reward'] ?? null;

        if (is_string($pack) && trim($pack) !== '') {
            return ['text' => $pack, 'source' => 'pack'];
        }

        return ['text' => (string) config('coupons.default_offer'), 'source' => 'default'];
    }

    /**
     * Create a live batch. The caller (the owner controller)
     * has already ruled couponFlowActive: a batch is never born
     * behind a paused door (pass Section 2.4, creation leg).
     */
    public function createBatch(Business $business, ?User $creator, string $label, string $offerText, int $claimCap, int $expiryDays, string $createdFrom, string $prefillSource): CouponBatch
    {
        $claimCap = max(1, min($claimCap, (int) config('coupons.claim_cap_hard_max')));
        $expiryDays = max(7, min($expiryDays, 90));
        $token = OpaqueToken::generate();

        $batch = CouponBatch::create([
            'business_id' => $business->id,
            'created_by' => $creator?->id,
            'label' => $label,
            'offer_text' => $offerText,
            'claim_cap' => $claimCap,
            'token_hash' => OpaqueToken::hash($token),
            'token_encrypted' => $token,
            'status' => CouponBatch::STATUS_ACTIVE,
            'expires_at' => now()->addDays($expiryDays),
        ]);

        // Instrumentation (pass Section 8.1 item 1): the friction
        // budget's own readout, including whether the owner
        // accepted the prefill or rewrote it.
        ReferralEvent::record('coupon_batch_created', 'owner', $business, null, null, null, [
            'batch_id' => $batch->id,
            'label' => $label,
            'cap' => $claimCap,
            'expiry_days' => $expiryDays,
            'prefill_source' => $prefillSource,
            'offer_accepted' => $offerText === $this->prefillOffer($business)['text'],
            'created_from' => $createdFrom,
        ]);

        return $batch;
    }

    /** Record one resolved landing (pass Section 5.2 scan). */
    public function recordScan(CouponBatch $batch, ?string $ipAddress = null): void
    {
        ReferralEvent::record('coupon_scan', 'customer', $batch->business, null, null, null, [
            'batch_id' => $batch->id,
        ], $ipAddress);
    }

    /**
     * The per-phone limits, checked before a verification is
     * started (so a limited phone spends no verification) and
     * re-checked at mint (pass Section 4.2 rules 1 and 2):
     * one claim per phone per batch in ANY state, and one claim
     * per phone per business per rolling window across batches.
     * Returns the limiting claim when one exists.
     *
     * @return array{scope: 'batch'|'business', claim: CouponClaim}|null
     */
    public function limitFor(CouponBatch $batch, Customer $customer): ?array
    {
        $perBatch = CouponClaim::query()
            ->where('batch_id', $batch->id)
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->first();

        if ($perBatch !== null) {
            return ['scope' => 'batch', 'claim' => $perBatch];
        }

        $windowStart = now()->subDays((int) config('coupons.per_business_window_days'));

        $perBusiness = CouponClaim::query()
            ->where('business_id', $batch->business_id)
            ->where('customer_id', $customer->id)
            ->where('created_at', '>=', $windowStart)
            ->latest('id')
            ->first();

        if ($perBusiness !== null) {
            return ['scope' => 'business', 'claim' => $perBusiness];
        }

        return null;
    }

    /** Find the customer for a verified phone, or create it. */
    public function findOrCreateCustomer(array $input): Customer
    {
        $customer = Customer::query()->where('phone_e164', $input['phone_e164'])->first();

        if ($customer !== null) {
            return $customer;
        }

        try {
            return Customer::create([
                'phone_e164' => $input['phone_e164'],
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'] ?? null,
                'canonical_email' => ($input['email'] ?? null) !== null
                    ? \App\Support\CanonicalEmail::of((string) $input['email'])
                    : null,
                'first_seen_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            return Customer::query()->where('phone_e164', $input['phone_e164'])->firstOrFail();
        }
    }

    /**
     * Mint a claim after verification (pass Section 2.2, state
     * C3): every gate is re-read at mint, because a batch can be
     * killed, filled, or paused between the landing and the
     * code check. The mint is IMMEDIATE: no gap, no earning
     * step. Outcomes: minted | limit | full | ended | paused.
     *
     * @param  array{first_name: string, last_name: string, phone_e164: string, email?: ?string}  $input
     * @return array{kind: string, claim: ?CouponClaim, limit_claim: ?CouponClaim}
     */
    public function mintClaim(CouponBatch $batch, array $input): array
    {
        return DB::transaction(function () use ($batch, $input) {
            $locked = CouponBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $business = $locked->business;

            $status = $locked->effectiveStatus();

            if ($status === CouponBatch::STATUS_KILLED || $status === CouponBatch::STATUS_EXPIRED) {
                return ['kind' => 'ended', 'claim' => null, 'limit_claim' => null];
            }

            if ($status === CouponBatch::STATUS_FULL) {
                return ['kind' => 'full', 'claim' => null, 'limit_claim' => null];
            }

            if (! $business->couponFlowActive()) {
                return ['kind' => 'paused', 'claim' => null, 'limit_claim' => null];
            }

            $customer = $this->findOrCreateCustomer($input);

            $limit = $this->limitFor($locked, $customer);

            if ($limit !== null) {
                return ['kind' => 'limit', 'claim' => null, 'limit_claim' => $limit['claim']];
            }

            if ($locked->claimsMinted() >= (int) $locked->claim_cap) {
                $locked->forceFill(['status' => CouponBatch::STATUS_FULL])->save();

                return ['kind' => 'full', 'claim' => null, 'limit_claim' => null];
            }

            $token = OpaqueToken::generate();

            $claim = CouponClaim::create([
                'business_id' => $business->id,
                'batch_id' => $locked->id,
                'customer_id' => $customer->id,
                'source' => 'coupon',
                // The snapshot: a later Settings or batch edit
                // can never rewrite what this claim promised
                // (pass Section 5.1, the EC-35 doctrine).
                'offer_text' => $locked->offer_text,
                'state' => CouponClaim::STATE_READY,
                'redeem_token_hash' => OpaqueToken::hash($token),
                'redeem_token_encrypted' => $token,
                'expires_at' => now()->addDays((int) $business->redemption_window_days),
            ]);

            if ($locked->claimsMinted() >= (int) $locked->claim_cap) {
                $locked->forceFill(['status' => CouponBatch::STATUS_FULL])->save();
            }

            $this->evaluateFlag($locked);

            return ['kind' => 'minted', 'claim' => $claim, 'limit_claim' => null];
        });
    }

    /**
     * Redeem a ready claim at the counter (pass Section 2.2,
     * state C4): the shipped state-guarded transition, exactly
     * once. Ready claims redeem through pause, card removal,
     * batch kill, and batch expiry, and through the closure
     * window; the claim's own expiry (which includes the
     * closure end) is the only clock that stops them. This
     * method creates NO billing event and mints NO reward.
     *
     * @return array{outcome: string}
     */
    public function redeemClaim(CouponClaim $claim, ?User $staffUser, ?string $ipAddress = null): array
    {
        $claim->applyExpiry();

        if ($claim->state === CouponClaim::STATE_REDEEMED) {
            return ['outcome' => 'already_redeemed'];
        }

        if ($claim->state !== CouponClaim::STATE_READY) {
            return ['outcome' => $claim->state];
        }

        $flipped = CouponClaim::query()
            ->where('id', $claim->id)
            ->where('state', CouponClaim::STATE_READY)
            ->update([
                'state' => CouponClaim::STATE_REDEEMED,
                'redeemed_at' => now(),
                'redeemed_by_user_id' => $staffUser?->id,
            ]);

        if ($flipped !== 1) {
            $claim->refresh();

            return ['outcome' => $claim->state === CouponClaim::STATE_REDEEMED ? 'already_redeemed' : $claim->state];
        }

        $claim->refresh();

        ReferralEvent::record('coupon_redeemed', 'staff', $claim->business, null, null, null, [
            'batch_id' => $claim->batch_id,
            'coupon_claim_id' => $claim->id,
        ], $ipAddress);

        return ['outcome' => 'redeemed'];
    }

    /** Kill a batch (pass Section 2.2, state C7): new claims stop; ready claims survive. */
    public function killBatch(CouponBatch $batch): void
    {
        if (in_array($batch->effectiveStatus(), [CouponBatch::STATUS_ACTIVE, CouponBatch::STATUS_FULL], true)) {
            $batch->forceFill(['status' => CouponBatch::STATUS_KILLED])->save();
        }
    }

    /** Void one ready claim (pass Section 2.2, state C10), owner only, with a reason. */
    public function voidClaim(CouponClaim $claim, string $reason): bool
    {
        if ($claim->state !== CouponClaim::STATE_READY) {
            return false;
        }

        $flipped = CouponClaim::query()
            ->where('id', $claim->id)
            ->where('state', CouponClaim::STATE_READY)
            ->update(['state' => CouponClaim::STATE_VOIDED, 'void_reason' => $reason]);

        if ($flipped === 1) {
            $claim->refresh();

            return true;
        }

        return false;
    }

    /**
     * The batch-level anomaly watch (pass Section 4.4, state
     * C11): burst and conversion signals over the batch's own
     * aggregates. A flag changes NOTHING for any customer or
     * claim; it only marks the batch for its owner's review of
     * the placement. Called at mint, the moment the numbers move.
     */
    public function evaluateFlag(CouponBatch $batch): void
    {
        if ($batch->isFlagged()) {
            return;
        }

        $mintsLastHour = $batch->claims()
            ->where('created_at', '>=', now()->subHour())
            ->count();
        $mintsPreviousHour = $batch->claims()
            ->where('created_at', '>=', now()->subHours(2))
            ->where('created_at', '<', now()->subHour())
            ->count();

        $burst = $mintsLastHour >= (int) config('coupons.flag_burst_min_mints')
            && $mintsLastHour >= (int) config('coupons.flag_burst_multiplier') * max(1, $mintsPreviousHour);

        $scans = $batch->scansCount();
        $minted = $batch->claimsMinted();
        $conversion = $scans >= (int) config('coupons.flag_conversion_min_scans')
            && $minted > 0
            && ($minted / max(1, $scans)) >= (float) config('coupons.flag_conversion_ratio');

        if ($burst || $conversion) {
            $batch->forceFill(['flagged_at' => now()])->save();

            ReferralEvent::record('coupon_batch_flagged', 'system', $batch->business, null, null, null, [
                'batch_id' => $batch->id,
                'mints_last_hour' => $mintsLastHour,
                'scans' => $scans,
                'claims' => $minted,
                'signal' => $burst ? 'burst' : 'conversion',
            ]);
        }
    }

    /**
     * The per-batch funnel (pass Sections 5.2 and 8): every
     * number derived from the scan events and claim rows.
     *
     * @return array{scans: int, claimed: int, redeemed: int, scan_to_claim: ?float, claim_to_redemption: ?float}
     */
    public function batchFunnel(CouponBatch $batch): array
    {
        $scans = $batch->scansCount();
        $claimed = $batch->claimsMinted();
        $redeemed = $batch->claimsRedeemed();

        return [
            'scans' => $scans,
            'claimed' => $claimed,
            'redeemed' => $redeemed,
            'scan_to_claim' => $scans > 0 ? $claimed / $scans : null,
            'claim_to_redemption' => $claimed > 0 ? $redeemed / $claimed : null,
        ];
    }

    /**
     * The channel readout behind the kill metric (pass Section
     * 8.2): per-batch redemption conversion read only after a
     * batch has ended AND every claim window has closed, with
     * owner-test voids excluded from the denominator, plus the
     * second-batch rate. KILL below 15 percent on either.
     *
     * @return array{qualifying_batches: int, median_conversion: ?float, second_batch_rate: ?float}
     */
    public function channelReadout(): array
    {
        $conversions = [];
        $businessesWithEnded = 0;
        $businessesWithSecond = 0;

        $ended = CouponBatch::query()
            ->whereIn('status', [CouponBatch::STATUS_EXPIRED, CouponBatch::STATUS_KILLED, CouponBatch::STATUS_FULL])
            ->get();

        foreach ($ended as $batch) {
            $claims = $batch->claims()->get();
            $windowsClosed = $claims->every(fn (CouponClaim $claim) => $claim->state !== CouponClaim::STATE_READY);

            if (! $windowsClosed) {
                continue;
            }

            $denominator = $claims->reject(fn (CouponClaim $claim) => $claim->state === CouponClaim::STATE_VOIDED && $claim->void_reason === 'owner_test')->count();

            if ($denominator < 5) {
                continue;
            }

            $redeemed = $claims->where('state', CouponClaim::STATE_REDEEMED)->count();
            $conversions[] = $redeemed / $denominator;
        }

        $firstBatchByBusiness = CouponBatch::query()
            ->selectRaw('business_id, MIN(created_at) as first_at, COUNT(*) as batch_count')
            ->groupBy('business_id')
            ->get();

        foreach ($firstBatchByBusiness as $row) {
            $first = CouponBatch::query()->where('business_id', $row->business_id)->oldest('id')->first();

            if ($first === null || $first->effectiveStatus() === CouponBatch::STATUS_ACTIVE) {
                continue;
            }

            $businessesWithEnded++;
            // The 60-day window is measured from the first
            // batch's END; expires_at is the end for a batch
            // that ran its course.
            $secondWithin60 = CouponBatch::query()
                ->where('business_id', $row->business_id)
                ->where('id', '!=', $first->id)
                ->where('created_at', '<=', $first->expires_at->copy()->addDays(60))
                ->exists();

            if ($secondWithin60) {
                $businessesWithSecond++;
            }
        }

        sort($conversions);
        $median = null;

        if ($conversions !== []) {
            $middle = intdiv(count($conversions), 2);
            $median = count($conversions) % 2 === 1
                ? $conversions[$middle]
                : ($conversions[$middle - 1] + $conversions[$middle]) / 2;
        }

        return [
            'qualifying_batches' => count($conversions),
            'median_conversion' => $median,
            'second_batch_rate' => $businessesWithEnded > 0 ? $businessesWithSecond / $businessesWithEnded : null,
        ];
    }

    /**
     * Whether the benefit hint shows for this owner (pass
     * Section 6.2): until the first batch is created or the
     * hint is dismissed, then quiet for good.
     */
    public function hintVisible(Business $business, ?User $user): bool
    {
        if ($business->couponBatches()->exists()) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        return ! \App\Models\CouponHintDismissal::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
