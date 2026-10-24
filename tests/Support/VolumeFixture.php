<?php

namespace Tests\Support;

use App\Models\Business;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Volume fixture for the performance budget (verification
 * protocol Section 2.5, test matrix XT-44): one business with a
 * long referral history, built with chunked query-builder inserts
 * because model-by-model creation of tens of thousands of rows
 * would dominate the measurement it exists to support. Test
 * environment only; nothing here is reachable from app code.
 *
 * The history shape mirrors production proportions: about 70% of
 * referrals redeemed (each with its referrer reward and billing
 * event), 15% expired, 10% still pending with live expiry, 5%
 * voided, a thin held slice for the review queue, and redeemed
 * timestamps spread over the last 120 days. Pending rows carry a
 * future expiry and vesting rows a future availability, so the
 * dashboard's on-read transitions find a swept, steady state and
 * do no write work, exactly as a nightly-swept production
 * business would present.
 */
final class VolumeFixture
{
    private const REFERRERS = 200;

    private const CHUNK = 500;

    /** @return array{0: Business, 1: User} the business and its owner */
    public static function businessWithHistory(int $referralCount): array
    {
        $business = World::business();
        $owner = World::owner($business);
        $now = now();

        $referrerCustomerIds = self::insertCustomers($business->id, 'referrer', self::REFERRERS, $now);
        $referrerMembershipIds = self::insertMemberships($business->id, $referrerCustomerIds, $now);

        $friendCustomerIds = self::insertCustomers($business->id, 'friend', $referralCount, $now);
        self::insertMemberships($business->id, $friendCustomerIds, $now);

        $referralIds = self::insertReferrals($business->id, $referrerMembershipIds, $friendCustomerIds, $now);
        self::insertRewards($business->id, $referrerMembershipIds, $referralIds, $now);
        self::insertBillingEvents($business->id, $referralIds, $now);
        self::insertReferralEvents($business->id, $referrerMembershipIds, $referralIds, $now);
        self::insertSmsMessages($business->id, $friendCustomerIds, $now);

        return [$business->fresh(), $owner];
    }

    /** Deterministic, globally unique phone per business and row. */
    private static function phoneFor(int $businessId, string $kind, int $i): string
    {
        $base = $kind === 'referrer' ? 10000 : 20000;

        return '+1'.str_pad((string) ($businessId * 100000 + $base + $i), 10, '0', STR_PAD_LEFT);
    }

    /** @return list<int> inserted customer ids in insertion order */
    private static function insertCustomers(int $businessId, string $kind, int $count, Carbon $now): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'phone_e164' => self::phoneFor($businessId, $kind, $i),
                'first_name' => $kind === 'referrer' ? 'Referrer' : 'Friend',
                'last_name' => 'Number '.$i,
                'email' => null,
                'canonical_email' => null,
                'first_seen_at' => $now->copy()->subDays(120)->format('Y-m-d H:i:s'),
                'number_linked_at' => null,
                'created_at' => $now->copy()->subDays(120)->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        $before = (int) DB::table('customers')->max('id');

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        return DB::table('customers')->where('id', '>', $before)->orderBy('id')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $customerIds
     * @return list<int> inserted membership ids in insertion order
     */
    private static function insertMemberships(int $businessId, array $customerIds, Carbon $now): array
    {
        $rows = [];
        foreach ($customerIds as $position => $customerId) {
            $rows[] = [
                'business_id' => $businessId,
                'customer_id' => $customerId,
                'share_token_hash' => hash('sha256', "share-{$businessId}-{$customerId}"),
                'share_token_state' => 'active',
                'sms_consent' => true,
                'consent_source' => 'customer_checkbox',
                'consent_wording_version' => 'v1',
                'consent_updated_at' => $now->copy()->subDays($position % 120)->format('Y-m-d H:i:s'),
                'referral_count' => 0,
                'removed_at' => null,
                'created_at' => $now->copy()->subDays($position % 120)->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        $before = (int) DB::table('memberships')->max('id');

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('memberships')->insert($chunk);
        }

        return DB::table('memberships')->where('id', '>', $before)->orderBy('id')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * State mix per 20 rows: 14 redeemed, 3 expired, 2 pending,
     * 1 voided.
     *
     * @param  list<int>  $referrerMembershipIds
     * @param  list<int>  $friendCustomerIds
     * @return list<int> inserted referral ids in insertion order
     */
    private static function insertReferrals(int $businessId, array $referrerMembershipIds, array $friendCustomerIds, Carbon $now): array
    {
        $rows = [];
        $total = count($friendCustomerIds);

        for ($i = 0; $i < $total; $i++) {
            $bucket = $i % 20;
            $state = match (true) {
                $bucket < 14 => Referral::STATE_REDEEMED,
                $bucket < 17 => Referral::STATE_EXPIRED,
                $bucket < 19 => Referral::STATE_PENDING,
                default => Referral::STATE_VOIDED,
            };
            $dayOffset = $i % 120;
            $created = $now->copy()->subDays($dayOffset)->subMinutes($i % 720);
            $redeemedAt = $state === Referral::STATE_REDEEMED
                ? $created->copy()->addDays(2)->min($now)
                : null;

            $rows[] = [
                'business_id' => $businessId,
                'referrer_membership_id' => $referrerMembershipIds[$i % count($referrerMembershipIds)],
                'referred_customer_id' => $friendCustomerIds[$i],
                'redeem_token_hash' => hash('sha256', "redeem-{$businessId}-{$i}"),
                'redeem_token_encrypted' => null,
                'state' => $state,
                'hold' => $i % 997 === 0,
                'hold_rules' => null,
                'held_at' => $i % 997 === 0 ? $created->format('Y-m-d H:i:s') : null,
                'hold_resolved_at' => null,
                'hold_resolved_by' => null,
                'risk_score' => 0,
                'risk_rules' => null,
                'redeemed_at' => $redeemedAt?->format('Y-m-d H:i:s'),
                'redeemed_by_user_id' => null,
                'attribution_state' => $state === Referral::STATE_REDEEMED
                    ? ($i % 499 === 0 ? 'unattributed' : 'attributed')
                    : null,
                'expires_at' => $state === Referral::STATE_PENDING
                    ? $now->copy()->addDays(30)->format('Y-m-d H:i:s')
                    : $created->copy()->addDays(30)->format('Y-m-d H:i:s'),
                'created_at' => $created->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        $before = (int) DB::table('referrals')->max('id');

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('referrals')->insert($chunk);
        }

        return DB::table('referrals')->where('id', '>', $before)->orderBy('id')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * One referrer reward per redeemed referral, in the same row
     * order the referrals were inserted: 55% claimed, 35%
     * available, 10% still vesting with a future availability.
     *
     * @param  list<int>  $referrerMembershipIds
     * @param  list<int>  $referralIds
     */
    private static function insertRewards(int $businessId, array $referrerMembershipIds, array $referralIds, Carbon $now): void
    {
        $referrals = DB::table('referrals')
            ->whereIn('id', $referralIds)
            ->where('state', Referral::STATE_REDEEMED)
            ->orderBy('id')
            ->get(['id', 'referrer_membership_id', 'redeemed_at']);

        $rows = [];
        foreach ($referrals as $position => $referral) {
            $bucket = $position % 20;
            $state = match (true) {
                $bucket < 11 => Reward::STATE_CLAIMED,
                $bucket < 18 => Reward::STATE_AVAILABLE,
                default => Reward::STATE_PENDING_VESTING,
            };

            $rows[] = [
                'business_id' => $businessId,
                'referral_id' => (int) $referral->id,
                'membership_id' => (int) $referral->referrer_membership_id,
                'reward_text_snapshot' => '$10 off',
                'state' => $state,
                'hold' => $position % 991 === 0,
                'hold_rules' => null,
                'held_at' => $position % 991 === 0 ? $now->copy()->subDay()->format('Y-m-d H:i:s') : null,
                'hold_resolved_at' => null,
                'hold_resolved_by' => null,
                'claim_token_hash' => hash('sha256', "claim-{$businessId}-{$referral->id}"),
                'claim_token_encrypted' => null,
                'available_at' => $state === Reward::STATE_PENDING_VESTING
                    ? $now->copy()->addDays(3)->format('Y-m-d H:i:s')
                    : $now->copy()->subDays(2)->format('Y-m-d H:i:s'),
                'claimed_at' => $state === Reward::STATE_CLAIMED ? $referral->redeemed_at : null,
                'claimed_by_user_id' => null,
                'notification_state' => 'sent',
                'created_at' => $referral->redeemed_at,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('rewards')->insert($chunk);
        }
    }

    /** One billing event per redeemed referral, at the plan's $2. */
    private static function insertBillingEvents(int $businessId, array $referralIds, Carbon $now): void
    {
        $referrals = DB::table('referrals')
            ->whereIn('id', $referralIds)
            ->where('state', Referral::STATE_REDEEMED)
            ->orderBy('id')
            ->get(['id', 'redeemed_at']);

        $rows = [];
        foreach ($referrals as $referral) {
            $rows[] = [
                'business_id' => $businessId,
                'referral_id' => (int) $referral->id,
                'billing_record_id' => null,
                'amount_cents' => 200,
                'coverage' => 'billable',
                'created_at' => $referral->redeemed_at,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('billing_events')->insert($chunk);
        }
    }

    /**
     * One scan event per referral at its creation time, a small
     * burst of scans today, and one poster download, so every
     * dashboard tile reads a live number.
     *
     * @param  list<int>  $referrerMembershipIds
     * @param  list<int>  $referralIds
     */
    private static function insertReferralEvents(int $businessId, array $referrerMembershipIds, array $referralIds, Carbon $now): void
    {
        $createdAts = DB::table('referrals')->whereIn('id', $referralIds)->orderBy('id')->pluck('created_at');

        $rows = [];
        foreach ($referralIds as $position => $referralId) {
            $rows[] = [
                'business_id' => $businessId,
                'referral_id' => $referralId,
                'reward_id' => null,
                'membership_id' => $referrerMembershipIds[$position % count($referrerMembershipIds)],
                'event_type' => 'scan',
                'actor' => 'customer',
                'ip_address' => null,
                'device_signal_hash' => null,
                'metadata' => null,
                'created_at' => $createdAts[$position],
            ];
        }

        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'business_id' => $businessId,
                'referral_id' => null,
                'reward_id' => null,
                'membership_id' => $referrerMembershipIds[0],
                'event_type' => 'scan',
                'actor' => 'customer',
                'ip_address' => null,
                'device_signal_hash' => null,
                'metadata' => null,
                'created_at' => $now->copy()->subMinutes($i + 1)->format('Y-m-d H:i:s'),
            ];
        }

        $rows[] = [
            'business_id' => $businessId,
            'referral_id' => null,
            'reward_id' => null,
            'membership_id' => $referrerMembershipIds[0],
            'event_type' => 'poster_downloaded',
            'actor' => 'owner',
            'ip_address' => null,
            'device_signal_hash' => null,
            'metadata' => null,
            'created_at' => $now->copy()->subDay()->format('Y-m-d H:i:s'),
        ];

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('referral_events')->insert($chunk);
        }
    }

    /** A small set of delivered campaign messages for the promo tile. */
    private static function insertSmsMessages(int $businessId, array $friendCustomerIds, Carbon $now): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = [
                'business_id' => $businessId,
                'customer_id' => $friendCustomerIds[$i],
                'phone_e164' => self::phoneFor($businessId, 'friend', $i),
                'purpose' => 'campaign',
                'messaging_service' => 'promotional',
                'template_key' => 'campaign_generic',
                'rendered_body' => 'A message from your store.',
                'twilio_message_sid' => null,
                'delivery_state' => 'delivered',
                'consent_basis' => 'membership',
                'opt_out_state_at_send' => false,
                'created_at' => $now->copy()->subDays(2)->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        DB::table('sms_messages')->insert($rows);
    }
}
