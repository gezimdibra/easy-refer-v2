<?php

namespace App\Services\Referral;

use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\CustomerNumberHistory;
use App\Models\Membership;
use App\Models\QrCode;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Services\Attribution\AttributionService;
use App\Support\CanonicalEmail;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\DB;

/**
 * The customer flow's write paths (spec Section 3): enrollment
 * completion (Step 5), friend claim (Steps 7 and 8), and the
 * integrity rules of Section 3.1. Nothing in this service runs
 * before a successful verification: an abandoned verification
 * leaves no customer, membership, or referral row behind (REF-22).
 */
class EnrollmentService
{
    public function __construct(
        private RiskScoring $risk,
        private AttributionService $attribution,
    ) {}

    /**
     * Complete an enrollment after verification: find or create the
     * customer and membership in one transaction, record the
     * consent projection, and write the scan and enrollment events.
     *
     * @param  array{first_name: string, last_name: string, phone_e164: string, email: ?string, consent: bool, scan_event_pending: bool}  $input (last_name is '' when the customer did not give one, F-6)
     * @return array{membership: Membership, customer: Customer, created_membership: bool, share_token: ?string}
     */
    public function completeEnrollment(Business $business, array $input, ?string $ipAddress, ?string $deviceSignalHash, bool $recordScan, ?int $scanQrCodeId = null, ?string $scanPlacement = null): array
    {
        return DB::transaction(function () use ($business, $input, $ipAddress, $deviceSignalHash, $recordScan, $scanQrCodeId, $scanPlacement) {
            $customer = $this->findOrCreateCustomer($input, $business, $ipAddress);

            [$membership, $created, $shareToken] = Membership::findOrCreateWithToken($business, $customer);

            $this->applyConsent($membership, (bool) $input['consent'], 'enrollment', $ipAddress);

            if ($recordScan) {
                // QRK-05: stamp which code produced this scan
                // (id + placement), so per-placement counts on
                // the owner's QR page derive from the events.
                $scanMetadata = array_filter([
                    'qr_code_id' => $scanQrCodeId,
                    'placement' => $scanPlacement,
                ], fn ($value) => $value !== null);
                ReferralEvent::record('scan', 'customer', $business, null, null, $membership, $scanMetadata, $ipAddress, $deviceSignalHash);
                $this->fireFirstScanOnce($business);
            }

            ReferralEvent::record('enrollment', 'customer', $business, null, null, $membership, [], $ipAddress, $deviceSignalHash);
            ReferralEvent::record('verification_passed', 'customer', $business, null, null, $membership, [], $ipAddress, $deviceSignalHash);

            return [
                'membership' => $membership,
                'customer' => $customer,
                'created_membership' => $created,
                'share_token' => $shareToken,
            ];
        });
    }

    /**
     * Complete a friend claim after verification (Step 8): integrity
     * checks, dedupe, referral creation with risk scoring recorded
     * as data only (Option B: creation never holds; redemption
     * converts the flags), all in one transaction. The outcome
     * tells the controller which screen the friend lands on.
     *
     * @param  array{first_name: string, last_name: string, phone_e164: string, email: ?string, consent: bool}  $input (last_name '' when not given, F-6)
     * @return array{kind: string, referral: ?Referral, membership: ?Membership}
     */
    public function completeClaim(Membership $referrerMembership, array $input, ?string $ipAddress, ?string $deviceSignalHash): array
    {
        $business = $referrerMembership->business;
        $referrerCustomer = $referrerMembership->customer;

        // Integrity first (Section 3.1, EC-11): the referrer's own
        // phone, or any phone belonging to the business's owner,
        // earns nothing at this business. These checks run before
        // any row is created for the claimant.
        if ($input['phone_e164'] === $referrerCustomer->phone_e164) {
            return ['kind' => 'self_phone', 'referral' => null, 'membership' => null];
        }

        if ($business->users()->where('phone_e164', $input['phone_e164'])->exists()) {
            return ['kind' => 'owner_phone', 'referral' => null, 'membership' => null];
        }

        return DB::transaction(function () use ($business, $referrerMembership, $referrerCustomer, $input, $ipAddress, $deviceSignalHash) {
            $friend = $this->findOrCreateCustomer($input, $business, $ipAddress);
            [$friendMembership, ,] = Membership::findOrCreateWithToken($business, $friend);
            $this->applyConsent($friendMembership, (bool) $input['consent'], 'friend_claim', $ipAddress);

            // The self-referral rule's email leg (EC-11): the same
            // person behind a different phone is caught by the
            // canonical email match.
            if (($input['email'] ?? null) !== null && $referrerCustomer->canonical_email !== null
                && CanonicalEmail::of((string) $input['email']) === $referrerCustomer->canonical_email) {
                return ['kind' => 'self_email', 'referral' => null, 'membership' => $friendMembership];
            }

            // Defensive second belt on the self rule: phone
            // resolution above already returns the referrer only
            // for the referrer's own number, which the early
            // return intercepts; this guard keeps the rule true
            // if identity resolution ever widens (for example to
            // linked numbers, EC-16).
            if ($friend->id === $referrerCustomer->id) {
                return ['kind' => 'self_phone', 'referral' => null, 'membership' => $friendMembership];
            }

            ReferralEvent::record('verification_passed', 'customer', $business, null, null, $friendMembership, [], $ipAddress, $deviceSignalHash);

            // Dedupe (Section 3.1; BREAKER D-1, 2026-10-29): a
            // visit counts ONCE per (business, friend) — the
            // governing referral for the pair decides this claim,
            // whoever's link the friend arrived through:
            //  - redeemed: the visit already counted. The claim
            //    resolves to that referral ('already_redeemed');
            //    the controller lands the friend on their existing
            //    visit page. No second referral, reward, or fee.
            //  - live pending: returned as-is ('existing', the
            //    shipped shape). An expired pending referral is
            //    flipped under its row lock first, freeing the
            //    slot: no visit ever counted, so a new claim is
            //    legal and is created below.
            //  - voided: the pair's claim was denied by the owner.
            //    Deny is terminal for the pair ('denied'); a fresh
            //    claim would defeat the deny, so none is created.
            $governing = $this->governingReferral($business, $friend);

            if ($governing !== null) {
                return ['kind' => $governing['kind'], 'referral' => $governing['referral'], 'membership' => $friendMembership];
            }

            // The 25-minute gap (owner rulings 2026-10-27 and
            // 2026-10-29): the friend's claim completes
            // immediately at any time; the gap is enforced ONLY
            // at redemption, inside RedemptionService. Nothing
            // in the claim flow evaluates, names, or conditions
            // on the window.

            // Fraud-hold conversion (Option B, owner ruling
            // 2026-10-28; V-B compute-at-redemption): claim creation
            // NEVER holds. A velocity breach at this moment is not
            // recorded at all — the redemption-time recheck
            // (RedemptionService::redemptionVelocityBreach) is the
            // authoritative velocity gate, evaluated against
            // redeemed counts when the visit actually happens. The
            // friend's journey from here is identical to an
            // unflagged claim in every field and screen.
            $redeemToken = OpaqueToken::generate();

            try {
                $referral = Referral::create([
                    'business_id' => $business->id,
                    'referrer_membership_id' => $referrerMembership->id,
                    'referred_customer_id' => $friend->id,
                    // F-UP-1: snapshot the friend's promised reward
                    // text AT CLAIM CREATION, from the same source
                    // the claim flow itself renders at this moment:
                    // Business::rewardText(), the trimmed resolver of
                    // the friend_reward_text column the friend page's
                    // "Claim {text}" line is built from (no second
                    // derivation). This is the column's ONLY writer;
                    // later config edits never rewrite it, so
                    // historical surfaces can render what the friend
                    // was promised when they claimed. Display truth
                    // only: no money path reads this column.
                    // Merge note (dedupe merge 2026-10-30): the
                    // snapshot field survives inside the closer's
                    // try/catch insert, so a concurrent winner
                    // still resolves to the governing referral.
                    'friend_reward_text_snapshot' => $business->rewardText(),
                    'redeem_token_hash' => OpaqueToken::hash($redeemToken),
                    'redeem_token_encrypted' => $redeemToken,
                    'state' => Referral::STATE_PENDING,
                    'hold' => false,
                    'hold_rules' => null,
                    'held_at' => null,
                    'expires_at' => now()->addDays((int) $business->redemption_window_days),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // The counted-pair unique index (migration 000039)
                // is the data-layer backstop: a concurrent claim
                // for this pair won the insert race. Resolve to
                // the winner exactly as a sequential second claim
                // would; a violation from any other cause rethrows.
                $raced = $this->governingReferral($business, $friend);

                if ($raced === null) {
                    throw $e;
                }

                return ['kind' => $raced['kind'], 'referral' => $raced['referral'], 'membership' => $friendMembership];
            }

            // The shared-IP signal compares the claim's IP with
            // the referrer's own recorded flow IP (their latest
            // event carrying one). Alone it never holds (Section
            // 3.1); it only adds score.
            $referrerIp = ReferralEvent::query()
                ->where('membership_id', $referrerMembership->id)
                ->whereNotNull('ip_address')
                ->latest('id')
                ->value('ip_address');

            $scored = $this->risk->scoreNewReferral($referral, [
                'device_signal_hash' => $deviceSignalHash,
                'same_ip_as_referrer' => $ipAddress !== null && $referrerIp !== null && $referrerIp === $ipAddress,
            ]);

            // The risk score persists as DATA ONLY (Option B):
            // score and rules ride on the referral so redemption
            // can convert a holding rule (today only
            // device_signal_reuse, at the holdsAt threshold) into
            // a held reward + held billing coverage. No hold
            // fields are written here, and no flag_raised event
            // fires at creation — the single flag_raised for a
            // converted hold is recorded at redemption, against
            // the reward, exactly as the anomaly path does.
            $referral->forceFill([
                'risk_score' => $scored['score'],
                'risk_rules' => $scored['rules'] === [] ? null : $scored['rules'],
            ])->save();

            $referrerMembership->increment('referral_count');

            ReferralEvent::record('referral_created', 'customer', $business, $referral, null, $friendMembership, [], $ipAddress, $deviceSignalHash);

            return ['kind' => 'created', 'referral' => $referral, 'membership' => $friendMembership];
        });
    }

    /**
     * The governing referral for a (business, friend) pair, in
     * lifecycle priority (BREAKER D-1): a redeemed referral governs
     * forever (the visit counted); a live pending referral governs
     * while it lives (an expired one is flipped under its row lock
     * here, freeing the slot, and does not govern); a voided
     * referral governs over nothing else existing (the owner's deny
     * is terminal for the pair). Returns null when no referral
     * governs and a new claim may be created.
     *
     * @return array{kind: string, referral: Referral}|null
     */
    private function governingReferral(Business $business, Customer $friend): ?array
    {
        $pair = Referral::query()
            ->where('business_id', $business->id)
            ->where('referred_customer_id', $friend->id);

        // The governing redeemed row is the pair's SURVIVOR:
        // a redeemed row stamped pair_superseded_at by
        // migration 000039's resolution is a billed duplicate
        // awaiting its operational money decision, never the
        // visit the friend lands on. Fall back to any redeemed
        // row only if no survivor exists (defensive).
        $redeemed = (clone $pair)->where('state', Referral::STATE_REDEEMED)->whereNull('pair_superseded_at')->lockForUpdate()->first()
            ?? (clone $pair)->where('state', Referral::STATE_REDEEMED)->lockForUpdate()->first();

        if ($redeemed !== null) {
            return ['kind' => 'already_redeemed', 'referral' => $redeemed];
        }

        $pending = (clone $pair)->where('state', Referral::STATE_PENDING)->lockForUpdate()->first();

        if ($pending !== null && ! $pending->applyExpiry()) {
            return ['kind' => 'existing', 'referral' => $pending];
        }

        // BREAKER close (two-key Finding 5): the voided check
        // must also run when a pending row EXISTED but its
        // expiry flip just freed it. The claim-step gate reads
        // raw states, so a pair holding a time-expired pending
        // row plus a voided row skips the gate's shortcut (the
        // pluck still contains 'pending_redemption'); before
        // this fix that same shape fell through here and minted
        // a NEW claim over the owner's deny. Deny is terminal
        // for the pair in every shape.
        $voided = (clone $pair)->where('state', Referral::STATE_VOIDED)->lockForUpdate()->first();

        if ($voided !== null) {
            return ['kind' => 'denied', 'referral' => $voided];
        }

        return null;
    }

    /**
     * The per-referrer velocity caps (Section 3.1): rewarded
     * referrals in the business-local day and month, against the
     * business's configured caps. Returns the breached rule ID.
     *
     * NOTE (Option B conversion, 2026-10-28): claim creation no
     * longer calls this method and no longer holds on it —
     * redemption is the velocity gate (RedemptionService::
     * redemptionVelocityBreach, counted on redeemed referrals).
     * This creation-count definition is retained deliberately;
     * its removal, if ever wanted, is a separate cleanup.
     */
    public function velocityBreach(Business $business, Membership $referrerMembership): ?string
    {
        $timezone = new \DateTimeZone($business->timezone ?: 'America/New_York');

        $today = Referral::query()
            ->where('business_id', $business->id)
            ->where('referrer_membership_id', $referrerMembership->id)
            ->where('hold', false)
            ->where('state', '!=', Referral::STATE_VOIDED)
            ->where('created_at', '>=', now()->setTimezone($timezone)->startOfDay()->setTimezone(new \DateTimeZone('UTC')))
            ->count();

        if ($today >= (int) $business->daily_referral_cap) {
            return 'velocity_daily';
        }

        $thisMonth = Referral::query()
            ->where('business_id', $business->id)
            ->where('referrer_membership_id', $referrerMembership->id)
            ->where('hold', false)
            ->where('state', '!=', Referral::STATE_VOIDED)
            ->where('created_at', '>=', now()->setTimezone($timezone)->startOfMonth()->setTimezone(new \DateTimeZone('UTC')))
            ->count();

        if ($thisMonth >= (int) $business->monthly_referral_cap) {
            return 'velocity_monthly';
        }

        return null;
    }

    /**
     * Find the canonical customer for a verified phone, or create
     * it. A name difference on a shared phone updates the shared
     * record with an audit event (EC-17: the phone is the identity;
     * there is no second identity to create).
     */
    private function findOrCreateCustomer(array $input, Business $business, ?string $ipAddress): Customer
    {
        $customer = Customer::query()->where('phone_e164', $input['phone_e164'])->first();

        if ($customer === null) {
            try {
                return Customer::create([
                    'phone_e164' => $input['phone_e164'],
                    'first_name' => $input['first_name'],
                    'last_name' => $input['last_name'],
                    'email' => $input['email'] ?? null,
                    'canonical_email' => ($input['email'] ?? null) !== null ? CanonicalEmail::of((string) $input['email']) : null,
                    'first_seen_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException) {
                return Customer::query()->where('phone_e164', $input['phone_e164'])->firstOrFail();
            }
        }

        // F-6: the last name is optional on the enroll and claim
        // forms. A blank submission means "not given", never
        // "erase it": a stored last name survives a later blank
        // form, so the shared-phone name update below compares
        // against the name the customer actually provided.
        if (($input['last_name'] ?? '') === '' && $customer->last_name !== '') {
            $input['last_name'] = $customer->last_name;
        }

        if ($customer->first_name !== $input['first_name'] || $customer->last_name !== $input['last_name']) {
            $customer->forceFill([
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
            ])->save();

            ReferralEvent::record('enrollment', 'customer', $business, null, null, null, [
                'detail' => 'shared_phone_name_updated',
            ], $ipAddress);
        }

        if (($input['email'] ?? null) !== null && $customer->canonical_email === null) {
            $customer->forceFill([
                'email' => $input['email'],
                'canonical_email' => CanonicalEmail::of((string) $input['email']),
            ])->save();
        }

        return $customer;
    }

    /**
     * Link a customer's verified new number (EC-16): called when a
     * flow session established under one verified number completes a
     * verification for a different number at the same business. The
     * cooling window (REF-54) is stamped on BOTH identities and each
     * identity's history records the counterpart number: the claim
     * defense reads the stamp from whichever customer record holds
     * the reward being claimed, so marking only one side would leave
     * the other side's claims unchecked (security verifier M-3).
     */
    public function linkNumber(Customer $customer, Customer $previous, Business $business, ?string $ipAddress): void
    {
        DB::transaction(function () use ($customer, $previous, $business, $ipAddress) {
            foreach ([[$customer, $previous->phone_e164], [$previous, $customer->phone_e164]] as [$record, $counterpart]) {
                CustomerNumberHistory::create([
                    'customer_id' => $record->id,
                    'phone_e164' => $counterpart,
                    'linked_at' => now(),
                    'link_reason' => 'verified_with_new_number',
                ]);

                $record->forceFill(['number_linked_at' => now()])->save();
            }

            ReferralEvent::record('number_linked', 'customer', $business, null, null, null, [
                'customer_id' => $customer->id,
            ], $ipAddress);
        });
    }

    /**
     * The consent projection (Section 7.2): the membership row
     * carries the current state; the change is appended to the
     * consent event log with its source and wording version.
     */
    private function applyConsent(Membership $membership, bool $consent, string $flow, ?string $ipAddress): void
    {
        if ((bool) $membership->sms_consent === $consent && $membership->consent_source !== null) {
            return;
        }

        $membership->forceFill([
            'sms_consent' => $consent,
            'consent_source' => 'customer_checkbox',
            'consent_wording_version' => (string) config('referrals.consent_wording_version'),
            'consent_updated_at' => now(),
        ])->save();

        ConsentEvent::create([
            'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id,
            'business_id' => $membership->business_id,
            'event_type' => $consent ? 'granted' : 'revoked',
            'source' => 'customer_checkbox',
            'consent_wording_version' => (string) config('referrals.consent_wording_version'),
            'flow' => $flow,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * E6 qr_first_scan (Section 16): fires when the business's first
     * scan event exists, exactly once.
     */
    private function fireFirstScanOnce(Business $business): void
    {
        $already = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'scan')
            ->count();

        if ($already === 1) {
            $this->attribution->record('E6', $business);
        }
    }

    /**
     * Resolve a QR token for an enrollment submit, honoring the
     * revocation grace (Section 3.1): a flow session already in
     * flight on a just-revoked code may complete within 15 minutes
     * of the revocation.
     */
    public function resolveQrForSubmit(string $token, ?int $flowBusinessId, ?\DateTimeInterface $flowCreatedAt): ?QrCode
    {
        $qr = QrCode::query()->where('token_hash', OpaqueToken::hash($token))->first();

        if ($qr === null) {
            return null;
        }

        if ($qr->state === 'active') {
            return $qr;
        }

        if ($qr->state === 'revoked' && $qr->revoked_at !== null
            && $flowBusinessId === $qr->business_id
            && $flowCreatedAt !== null
            && $flowCreatedAt <= $qr->revoked_at
            && now()->lessThanOrEqualTo($qr->revoked_at->copy()->addMinutes(15))) {
            return $qr;
        }

        return null;
    }
}
