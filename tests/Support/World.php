<?php

namespace Tests\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\QrCode;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\User;
use App\Support\CanonicalEmail;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\Hash;

/**
 * Fixture builders for the Sprint 2 suites: an active business on
 * the launch plan with its owner, QR code, customers, memberships,
 * referrals, and rewards, without driving the whole signup and
 * verification journey for every assertion.
 */
final class World
{
    private static int $sequence = 0;

    /**
     * Reset the fixture sequence. Called from TestCase::setUp() so
     * every test numbers its businesses from 1: without the reset the
     * process-global counter eventually generates an owner phone in
     * the 212555xxxx range that feature tests hard-code as customer
     * phones (n=111, 212, 777, 999), and the fixture business silently
     * takes the owner_phone branch in the refer flow.
     */
    public static function resetSequence(): void
    {
        self::$sequence = 0;
    }

    public static function business(array $overrides = []): Business
    {
        self::$sequence++;
        $n = self::$sequence;
        $phone = '+1212555'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);

        $business = Business::create(array_merge([
            'name' => "Business {$n}",
            'contact_email' => "owner{$n}@example.test",
            'contact_phone_e164' => $phone,
            'country' => 'US',
            'street' => '1 Test Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'timezone' => 'America/New_York',
            'status' => 'active',
            'billing_status' => 'active',
            'free_visits_remaining' => (int) config('billing.free_visits_at_signup'),
            'email_verified_at' => now(),
            'current_plan_id' => Plan::launch()->id,
            'friend_reward_text' => '$10 off your first visit',
            'referrer_reward_text' => '$10 off',
            'average_ticket_cents' => 5000,
        ], $overrides));

        User::create([
            'business_id' => $business->id,
            'role' => 'owner',
            'name' => "Owner {$n}",
            'email' => "owner{$n}@example.test",
            'canonical_email' => CanonicalEmail::of("owner{$n}@example.test"),
            'phone_e164' => $phone,
            'password' => Hash::make('WorldPassword01!'),
            'email_verified_at' => now(),
        ]);

        return $business->fresh();
    }

    public static function owner(Business $business): User
    {
        return User::query()->where('business_id', $business->id)->firstOrFail();
    }

    /** @return array{0: QrCode, 1: string} the code and its plain token */
    public static function qr(Business $business): array
    {
        $plain = QrCode::issueFor($business);
        $qr = QrCode::query()->where('token_hash', OpaqueToken::hash($plain))->firstOrFail();

        return [$qr, $plain];
    }

    public static function customer(string $phone, string $first = 'Sam', string $last = 'Reed', array $overrides = []): Customer
    {
        $existing = Customer::query()->where('phone_e164', $phone)->first();

        if ($existing !== null && $overrides === []) {
            return $existing;
        }

        return Customer::create(array_merge([
            'phone_e164' => $phone,
            'first_name' => $first,
            'last_name' => $last,
            'first_seen_at' => now(),
        ], $overrides));
    }

    /** @return array{0: Membership, 1: string} the membership and its plain share token */
    public static function membership(Business $business, Customer $customer): array
    {
        [$membership, , $plain] = Membership::findOrCreateWithToken($business, $customer);

        return [$membership, $plain];
    }

    /** @return array{0: Referral, 1: string} the referral and its plain redeem token */
    public static function referral(Membership $referrer, Customer $friend, array $overrides = []): array
    {
        $plain = OpaqueToken::generate();

        $referral = Referral::create(array_merge([
            'business_id' => $referrer->business_id,
            'referrer_membership_id' => $referrer->id,
            'referred_customer_id' => $friend->id,
            'redeem_token_hash' => OpaqueToken::hash($plain),
            'redeem_token_encrypted' => $plain,
            'state' => Referral::STATE_PENDING,
            'expires_at' => now()->addDays(30),
        ], $overrides));

        return [$referral, $plain];
    }

    /** @return array{0: Reward, 1: string} the reward and its plain claim token */
    public static function reward(Membership $membership, Referral $referral, array $overrides = []): array
    {
        $plain = OpaqueToken::generate();

        $reward = Reward::create(array_merge([
            'business_id' => $membership->business_id,
            'membership_id' => $membership->id,
            'referral_id' => $referral->id,
            'reward_text_snapshot' => '$10 off',
            'claim_token_hash' => OpaqueToken::hash($plain),
            'claim_token_encrypted' => $plain,
            'available_at' => now()->subDay(),
            'state' => Reward::STATE_AVAILABLE,
            'notification_state' => 'sent',
        ], $overrides));

        return [$reward, $plain];
    }
}
