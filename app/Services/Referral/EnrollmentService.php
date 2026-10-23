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
     * @param  array{first_name: string, last_name: string, phone_e164: string, email: ?string, consent: bool, scan_event_pending: bool}  $input
     * @return array{membership: Membership, customer: Customer, created_membership: bool, share_token: ?string}
     */
    public function completeEnrollment(Business $business, array $input, ?string $ipAddress, ?string $deviceSignalHash, bool $recordScan): array
    {
        return DB::transaction(function () use ($business, $input, $ipAddress, $deviceSignalHash, $recordScan) {
            $customer = $this->findOrCreateCustomer($input, $business, $ipAddress);

            [$membership, $created, $shareToken] = Membership::findOrCreateWithToken($business, $customer);

            $this->applyConsent($membership, (bool) $input['consent'], 'enrollment', $ipAddress);

            if ($recordScan) {
                ReferralEvent::record('scan', 'customer', $business, null, null, $membership, [], $ipAddress, $deviceSignalHash);
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
     * checks, dedupe, referral creation with risk scoring and
     * velocity holds, all in one transaction. The outcome tells the
     * controller which screen the friend lands on.
     *
     * @param  array{first_name: string, last_name: string, phone_e164: string, email: ?string, consent: bool}  $input
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

            // Dedupe (Section 3.1): a live pending referral for this
            // (business, friend) pair is returned as-is, whoever's
            // link the friend arrived through. An expired one is
            // flipped under its row lock first, freeing the slot.
            $existing = Referral::query()
                ->where('business_id', $business->id)
                ->where('referred_customer_id', $friend->id)
                ->where('state', Referral::STATE_PENDING)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $existing->applyExpiry()) {
                    return ['kind' => 'existing', 'referral' => $existing, 'membership' => $friendMembership];
                }
            }

            // Velocity (Section 3.1): past the referrer's daily or
            // monthly rewarded cap, the referral is recorded, earns
            // nothing, and waits in the review queue.
            $velocityRule = $this->velocityBreach($business, $referrerMembership);

            $redeemToken = OpaqueToken::generate();

            $referral = Referral::create([
                'business_id' => $business->id,
                'referrer_membership_id' => $referrerMembership->id,
                'referred_customer_id' => $friend->id,
                'redeem_token_hash' => OpaqueToken::hash($redeemToken),
                'redeem_token_encrypted' => $redeemToken,
                'state' => Referral::STATE_PENDING,
                'hold' => $velocityRule !== null,
                'hold_rules' => $velocityRule !== null ? [$velocityRule] : null,
                'held_at' => $velocityRule !== null ? now() : null,
                'expires_at' => now()->addDays((int) $business->redemption_window_days),
            ]);

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

            $holdRules = $referral->hold_rules ?? [];

            if ($this->risk->holdsAt($scored['score'])) {
                $holdRules = array_values(array_unique([...$holdRules, ...$scored['rules']]));
            }

            $referral->forceFill([
                'risk_score' => $scored['score'],
                'risk_rules' => $scored['rules'] === [] ? null : $scored['rules'],
                'hold' => $referral->hold || $holdRules !== [],
                'hold_rules' => $holdRules === [] ? null : $holdRules,
                'held_at' => ($referral->hold || $holdRules !== []) ? ($referral->held_at ?? now()) : null,
            ])->save();

            $referrerMembership->increment('referral_count');

            ReferralEvent::record('referral_created', 'customer', $business, $referral, null, $friendMembership, [], $ipAddress, $deviceSignalHash);

            if ($referral->hold) {
                ReferralEvent::record('flag_raised', 'system', $business, $referral, null, $friendMembership, [
                    'rules' => $referral->hold_rules,
                ], $ipAddress, $deviceSignalHash);
            }

            return ['kind' => 'created', 'referral' => $referral, 'membership' => $friendMembership];
        });
    }

    /**
     * The per-referrer velocity caps (Section 3.1): rewarded
     * referrals in the business-local day and month, against the
     * business's configured caps. Returns the breached rule ID.
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
