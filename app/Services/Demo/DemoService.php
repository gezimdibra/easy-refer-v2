<?php

namespace App\Services\Demo;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\DemoEvent;
use App\Models\DemoReseedLog;
use App\Models\DemoSession;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\QrCode;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\TokenNonce;
use App\Services\Security\RateBudgetService;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The demo's machinery (G6 demo Phase 1; design pass Sections
 * 5, 11, 12, 13): the Juniper Café seed and reseed, the start
 * guards, the claim completion that mints the ephemeral
 * identity and the demo claim on the real claim machinery's
 * shape, and the teardown that makes the phone-fate rule true.
 *
 * Demo data hygiene is keyed on the business's is_demo flag
 * and the claim's source='demo' stamp, never on the name.
 */
class DemoService
{
    public function __construct(
        private RateBudgetService $budgets,
    ) {}

    /**
     * The demo business, when seeded.
     */
    public function business(): ?Business
    {
        return Business::query()->where('is_demo', true)->first();
    }

    /**
     * Seed (or refresh) Juniper Café: fictional, flagged,
     * configured through the real machinery (the café starter
     * pack's first reward as its one reward, standard
     * placements issued), never chargeable by construction
     * (no Square customer, no card, no owner login; billing
     * reads exclude it by flag and its visits settle
     * coverage='demo').
     *
     * The billing_status reads 'active' so the business
     * configuration is the live shape; chargeability is a
     * property of payment methods and coverage, and the demo
     * has neither a payment method nor a billable coverage.
     */
    public function ensureBusiness(): Business
    {
        $name = (string) config('demo.business_name');

        // Section 13.7: a real business already carrying the
        // name renames the seed. The flag carries every rule,
        // so the rename touches no logic.
        $collision = Business::query()
            ->where('name', $name)
            ->where('is_demo', false)
            ->exists();

        if ($collision) {
            $name = (string) config('demo.business_name_fallback');
        }

        $business = Business::query()->where('is_demo', true)->first();

        if ($business === null) {
            $business = Business::create([
                'name' => $name,
                'business_type' => 'cafe',
                'is_demo' => true,
                'slug' => (string) config('demo.business_slug'),
                'contact_email' => 'demo@easy-refer.example',
                'contact_phone_e164' => '+15550100100',
                'country' => 'US',
                'street' => '',
                'city' => '',
                'state' => '',
                'postal_code' => '',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'billing_status' => 'active',
                'current_plan_id' => Plan::launch()?->id,
                'free_visits_remaining' => (int) config('billing.free_visits_at_signup', 5),
            ]);
        }

        // The seeded configuration, restored on every reseed:
        // the C1 café starter pack's first reward, one reward
        // for both sides (the unified default), standard caps.
        $starter = \App\Support\StarterPacks::starterFor('cafe');
        $business->setRewardConfiguration($starter['reward'], false);
        $business->forceFill([
            'name' => $name,
            'status' => 'active',
            'closed_at' => null,
        ])->save();

        QrCode::ensurePlacements($business);

        return $business->refresh();
    }

    /**
     * Whether new demo starts are paused (Section 11.1's
     * resting state): the global daily ceiling is reached, or
     * the last two nightly reseeds both failed (Section 11.3).
     * In-flight sessions are never affected by this state.
     */
    public function resting(): bool
    {
        if ($this->startsToday() >= (int) config('demo.guards.global_starts_per_day')) {
            return true;
        }

        $lastTwo = DemoReseedLog::query()->orderByDesc('id')->limit(2)->pluck('succeeded');

        return $lastTwo->count() === 2 && $lastTwo->every(fn ($ok) => ! $ok);
    }

    /**
     * Demo starts recorded today (the demo's own event ledger
     * is the count of record for the global guards).
     */
    public function startsToday(): int
    {
        return DemoEvent::query()
            ->where('event', 'demo_started')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * The per-IP start guard (Section 12): 10 starts per hour,
     * 30 per day, counted by the shared rate-budget machinery
     * on the IP hash, never the raw address.
     *
     * @return 'ok'|'ip'
     */
    public function ipGuardState(string $ipAddress): string
    {
        $subject = hash('sha256', $ipAddress);

        // One key per window: the budget store keeps a single
        // window start per key, so the hour and day budgets
        // must not share one.
        if ($this->budgets->count(RateBudgetService::keyFor('demo_start_ip_h', $subject), 3600) >= (int) config('demo.guards.starts_per_ip_per_hour')) {
            return 'ip';
        }

        if ($this->budgets->count(RateBudgetService::keyFor('demo_start_ip_d', $subject), 86400) >= (int) config('demo.guards.starts_per_ip_per_day')) {
            return 'ip';
        }

        return 'ok';
    }

    /**
     * Record one admitted start against the per-IP budgets and
     * fire the 500/day spike alert when the day's count crosses
     * it (Section 12: an alert to us, no automatic action).
     * Call AFTER the start's demo_started event is recorded:
     * the day count reads that ledger.
     */
    public function recordStart(string $ipAddress): void
    {
        $subject = hash('sha256', $ipAddress);
        $this->budgets->hit(RateBudgetService::keyFor('demo_start_ip_h', $subject), 3600);
        $this->budgets->hit(RateBudgetService::keyFor('demo_start_ip_d', $subject), 86400);

        if ($this->startsToday() === (int) config('demo.guards.alert_starts_per_day')) {
            Log::warning('Demo starts crossed the daily alert threshold.', [
                'starts_today' => $this->startsToday(),
                'threshold' => (int) config('demo.guards.alert_starts_per_day'),
            ]);
        }
    }

    /**
     * The per-session ephemeral identity key (Section 5.3): a
     * demo-namespaced identity that can never collide with a
     * real phone-keyed customer.
     */
    public function ephemeralPhoneKey(DemoSession $session): string
    {
        return 'demo:'.substr(hash('sha256', 'demo-session:'.$session->id), 0, 24);
    }

    /**
     * Complete the demo claim after verification (the claim
     * machinery's shape, Section 3 S2 to S4): mint the
     * session-scoped ephemeral customer and its membership,
     * record the consent projection, and create the demo
     * claim: a referral row stamped source='demo' with NO
     * referrer (no referral relationship exists in the demo),
     * carrying a real single-use 128-bit redemption token.
     * Idempotent within the session: a re-submit or a Back
     * returns the session's existing claim and mints nothing
     * twice.
     *
     * @param  array{first_name: string, last_name: string, consent: bool}  $input
     * @return array{referral: Referral, membership: Membership, customer: Customer, created: bool}
     */
    public function completeClaim(Business $business, DemoSession $session, array $input, ?string $ipAddress): array
    {
        return DB::transaction(function () use ($business, $session, $input, $ipAddress) {
            $existingId = (int) ($session->data['referral_id'] ?? 0);

            if ($existingId > 0) {
                $existing = Referral::query()->whereKey($existingId)->where('business_id', $business->id)->first();

                if ($existing !== null) {
                    $membership = Membership::query()->findOrFail($session->data['membership_id']);

                    return [
                        'referral' => $existing,
                        'membership' => $membership,
                        'customer' => $membership->customer,
                        'created' => false,
                    ];
                }
            }

            $customer = Customer::create([
                'phone_e164' => $this->ephemeralPhoneKey($session),
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => null,
                'canonical_email' => null,
                'first_seen_at' => now(),
            ]);

            [$membership] = Membership::findOrCreateWithToken($business, $customer);

            if ((bool) $membership->sms_consent !== (bool) $input['consent'] || $membership->consent_source === null) {
                $membership->forceFill([
                    'sms_consent' => (bool) $input['consent'],
                    'consent_source' => 'customer_checkbox',
                    'consent_wording_version' => (string) config('referrals.consent_wording_version'),
                    'consent_updated_at' => now(),
                ])->save();

                ConsentEvent::create([
                    'membership_id' => $membership->id,
                    'customer_id' => $membership->id,
                    'business_id' => $business->id,
                    'event_type' => $input['consent'] ? 'granted' : 'revoked',
                    'source' => 'customer_checkbox',
                    'consent_wording_version' => (string) config('referrals.consent_wording_version'),
                    'flow' => 'demo_claim',
                    'ip_address' => $ipAddress,
                ]);
            }

            // The scan-equivalent entry, stamped with the
            // counter placement the demo frame stands in for.
            $counter = QrCode::activeForPlacement($business, 'counter');
            ReferralEvent::record('scan', 'customer', $business, null, null, $membership, array_filter([
                'qr_code_id' => $counter?->id,
                'placement' => $counter?->placement,
                'channel' => 'demo',
            ]), $ipAddress);
            ReferralEvent::record('verification_passed', 'customer', $business, null, null, $membership, [], $ipAddress);

            $redeemToken = OpaqueToken::generate();

            $referral = Referral::create([
                'business_id' => $business->id,
                'source' => 'demo',
                'referrer_membership_id' => null,
                'referred_customer_id' => $customer->id,
                'redeem_token_hash' => OpaqueToken::hash($redeemToken),
                'redeem_token_encrypted' => $redeemToken,
                'state' => Referral::STATE_PENDING,
                'hold' => false,
                'hold_rules' => null,
                'held_at' => null,
                'expires_at' => now()->addDays((int) $business->redemption_window_days),
            ]);

            ReferralEvent::record('referral_created', 'customer', $business, $referral, null, $membership, [
                'channel' => 'demo',
            ], $ipAddress);

            return [
                'referral' => $referral,
                'membership' => $membership,
                'customer' => $customer,
                'created' => true,
            ];
        });
    }

    /**
     * Delete every row one demo session created (Section 5.2's
     * teardown): the claim and its billing settlement row, the
     * events and consent records, the ephemeral membership and
     * customer. Called at restart and at reseed for the rows a
     * session leaves behind; expiry deletes the session record
     * itself, and the nightly reseed sweeps whatever remains.
     */
    public function teardownSessionRows(DemoSession $session): void
    {
        $referralId = (int) (($session->data ?? [])['referral_id'] ?? 0);
        $membershipId = (int) (($session->data ?? [])['membership_id'] ?? 0);
        $customerId = (int) (($session->data ?? [])['customer_id'] ?? 0);

        DB::transaction(function () use ($referralId, $membershipId, $customerId) {
            if ($referralId > 0) {
                BillingEvent::query()->where('referral_id', $referralId)->delete();
                Reward::query()->where('referral_id', $referralId)->delete();
                TokenNonce::query()->where('subject_type', 'referral')->where('subject_id', $referralId)->delete();
                ReferralEvent::query()->where('referral_id', $referralId)->delete();
                Referral::query()->whereKey($referralId)->delete();
            }

            if ($membershipId > 0) {
                ReferralEvent::query()->where('membership_id', $membershipId)->delete();
                ConsentEvent::query()->where('membership_id', $membershipId)->delete();
                Membership::query()->whereKey($membershipId)->delete();
            }

            if ($customerId > 0) {
                Customer::query()->whereKey($customerId)->delete();
            }
        });
    }

    /**
     * The nightly reseed (Section 11.3): every demo-derived row
     * is deleted, the configuration returns to the seeded
     * state, every demo session dies (its next interaction
     * lands on the timeout screen, whose "nothing was saved"
     * is then literally true), and the business opens the day
     * at an honest zero.
     */
    public function reseed(): Business
    {
        $business = $this->ensureBusiness();

        DB::transaction(function () use ($business) {
            $referralIds = Referral::query()->where('business_id', $business->id)->pluck('id');
            $membershipIds = Membership::query()->where('business_id', $business->id)->pluck('id');

            BillingEvent::query()->where('business_id', $business->id)->delete();
            Reward::query()->where('business_id', $business->id)->delete();
            TokenNonce::query()->where('subject_type', 'referral')->whereIn('subject_id', $referralIds)->delete();
            ReferralEvent::query()->where('business_id', $business->id)->delete();
            Referral::query()->where('business_id', $business->id)->delete();
            ConsentEvent::query()->where('business_id', $business->id)->delete();
            Membership::query()->where('business_id', $business->id)->delete();
            Customer::query()->where('phone_e164', 'like', 'demo:%')->delete();
            DemoSession::query()->delete();
        });

        return $this->ensureBusiness();
    }

    /**
     * A fresh opaque session key for the event ledger: carried
     * by events only, unrelated to the cookie token.
     */
    public function newSessionKey(): string
    {
        return (string) Str::uuid();
    }
}
