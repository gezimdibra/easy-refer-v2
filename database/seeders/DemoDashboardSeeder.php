<?php

namespace Database\Seeders;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\QrCode;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\User;
use App\Support\OpaqueToken;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEMO DATA ONLY. Seeds one clearly staging-grade business
 * ("Harbor Street Barbers") with a month of plausible activity so
 * the owner dashboard can be photographed for the marketing pages
 * (design AP-07: real product imagery only). This seeder is never
 * registered in DatabaseSeeder and must never run against a
 * production database: the account email is on example.com and
 * every phone is in the fictional 555-01XX range.
 */
class DemoDashboardSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now('America/New_York');

        $business = Business::create([
            'name' => 'Harbor Street Barbers',
            'slug' => 'harbor-street-barbers',
            'business_type' => 'barber',
            'contact_email' => 'demo@example.com',
            'contact_phone_e164' => '+17185550142',
            'street' => '214 Harbor Street',
            'city' => 'Staten Island',
            'state' => 'NY',
            'postal_code' => '10301',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'status' => 'active',
            'billing_status' => 'active',
            'current_plan_id' => Plan::launch()?->id,
            'free_visits_remaining' => 0,
            'friend_reward_text' => '$10 off your first cut',
            'referrer_reward_text' => '$10 off your next cut',
            'average_ticket_cents' => 4200,
            'google_review_url' => 'https://g.page/r/Cdemo/review',
            'email_verified_at' => $now->copy()->subDays(45),
        ]);
        $business->forceFill(['created_at' => $now->copy()->subDays(45), 'updated_at' => $now->copy()->subDays(45)])->save();

        $owner = User::create([
            'business_id' => $business->id,
            'role' => 'owner',
            'name' => 'Marcus Webb',
            'email' => 'demo@example.com',
            'canonical_email' => \App\Support\CanonicalEmail::of('demo@example.com'),
            'phone_e164' => '+17185550142',
            'password' => Hash::make('DemoPassword01!'),
            'email_verified_at' => $now->copy()->subDays(45),
        ]);

        QrCode::issueFor($business);

        $firstNames = ['James', 'Priya', 'Tom', 'Alicia', 'Rob', 'Nina', 'Chris', 'Fatima', 'Dan', 'Grace', 'Omar', 'Lena', 'Mike', 'Sara', 'Joe', 'Ivy', 'Paul', 'Ruth', 'Sam', 'Tara', 'Vic', 'Wendy', 'Yusuf', 'Zoe'];
        $lastNames = ['Miller', 'Sharma', 'Baker', 'Keys', 'Dunn', 'Petrova', 'Lane', 'Aziz', 'Ortiz', 'Hopper', 'Nasser', 'Frost', 'Reyes', 'Kim', 'Ford', 'Chen', 'Novak', 'Bell', 'Reed', 'Singh', 'Marsh', 'Doyle', 'Ali', 'Brooks'];

        $memberships = [];
        foreach ($firstNames as $i => $first) {
            $customer = Customer::create([
                'phone_e164' => '+1718555'.str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'last_name' => $lastNames[$i],
            ]);

            $joinedDaysAgo = max(1, 40 - (int) round($i * 1.7));
            $plain = OpaqueToken::generate();
            $membership = Membership::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'share_token_hash' => OpaqueToken::hash($plain),
                'share_token_encrypted' => $plain,
                'share_token_state' => 'active',
                'sms_consent' => true,
                'consent_source' => 'customer_checkbox',
                'consent_wording_version' => (string) config('referrals.consent_wording_version'),
                'consent_updated_at' => $now->copy()->subDays($joinedDaysAgo),
                'referral_count' => 0,
            ]);
            $membership->forceFill([
                'created_at' => $now->copy()->subDays($joinedDaysAgo),
                'updated_at' => $now->copy()->subDays($joinedDaysAgo),
            ])->save();

            $memberships[] = $membership;
        }

        // Referral activity: a rising month. Referrer weights make
        // a believable top-referrers table (the first three
        // memberships refer the most, including this week).
        $friendIndex = 0;
        $redeemedTotal = 0;
        $weights = [7, 6, 5, 3, 3, 2, 2, 2, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

        foreach ($weights as $referrerIndex => $count) {
            for ($n = 0; $n < $count; $n++) {
                $friendIndex++;
                $friend = Customer::create([
                    'phone_e164' => '+1718555'.str_pad((string) (200 + $friendIndex), 4, '0', STR_PAD_LEFT),
                    'first_name' => 'Friend'.$friendIndex,
                    'last_name' => 'Guest',
                ]);
                // Friend first names read better as real names.
                $friend->forceFill(['first_name' => $firstNames[($friendIndex * 7) % 24]])->save();

                $daysAgo = max(0, (int) round(28 - $redeemedTotal * 0.8));
                $redeemedTotal++;

                $referral = $this->makeReferral($memberships[$referrerIndex], $friend, $now->copy()->subDays($daysAgo + 1));
                $redeemedAt = $now->copy()->subDays($daysAgo)->setTime(9 + ($n % 3), 15);
                $referral->forceFill([
                    'state' => Referral::STATE_REDEEMED,
                    'redeemed_at' => $redeemedAt,
                    'attribution_state' => 'staff_account',
                ])->save();
                $referral->forceFill(['created_at' => $now->copy()->subDays($daysAgo + 1)])->save();

                BillingEvent::create([
                    'business_id' => $business->id,
                    'referral_id' => $referral->id,
                    'amount_cents' => 200,
                    'created_at' => $redeemedAt,
                ]);

                $rewardState = $daysAgo >= 14 ? Reward::STATE_CLAIMED : ($daysAgo >= 3 ? Reward::STATE_AVAILABLE : Reward::STATE_PENDING_VESTING);
                $reward = Reward::create([
                    'business_id' => $business->id,
                    'referral_id' => $referral->id,
                    'membership_id' => $memberships[$referrerIndex]->id,
                    'reward_text_snapshot' => '$10 off your next cut',
                    'state' => $rewardState,
                    'claim_token_hash' => OpaqueToken::hash($claimPlain = OpaqueToken::generate()),
                    'claim_token_encrypted' => $claimPlain,
                    'available_at' => $rewardState === Reward::STATE_PENDING_VESTING ? $now->copy()->addDays(14 - $daysAgo) : $redeemedAt,
                    'claimed_at' => $rewardState === Reward::STATE_CLAIMED ? $redeemedAt->copy()->addDays(2) : null,
                    'notification_state' => 'delivered',
                ]);
                $reward->forceFill(['created_at' => $redeemedAt])->save();

                ReferralEvent::record('redemption', 'counter', $business, $referral, null, $memberships[$referrerIndex]);
            }
        }

        // A few pending, one held, one expired.
        $pendingFriend = Customer::create(['phone_e164' => '+17185550199', 'first_name' => 'Noah', 'last_name' => 'Fields']);
        $this->makeReferral($memberships[1], $pendingFriend, $now->copy()->subDays(1));

        $heldFriend = Customer::create(['phone_e164' => '+17185550198', 'first_name' => 'Eli', 'last_name' => 'Vance']);
        $held = $this->makeReferral($memberships[0], $heldFriend, $now->copy()->subHours(5));
        $held->forceFill(['hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => $now->copy()->subHours(5)])->save();
        ReferralEvent::record('flag_raised', 'system', $business, $held, null, $memberships[0], ['rules' => ['velocity_daily']]);

        $expiredFriend = Customer::create(['phone_e164' => '+17185550197', 'first_name' => 'Mia', 'last_name' => 'Solano']);
        $expired = $this->makeReferral($memberships[3], $expiredFriend, $now->copy()->subDays(35));
        $expired->forceFill(['state' => Referral::STATE_EXPIRED])->save();

        // Today's scans for the tile.
        foreach (range(1, 6) as $i) {
            ReferralEvent::record('scan', 'customer', $business, null, null, $memberships[$i]);
        }

        ReferralEvent::record('poster_downloaded', 'owner', $business);

        $owner->forceFill(['created_at' => $now->copy()->subDays(45)])->save();
    }

    private function makeReferral(Membership $referrer, Customer $friend, Carbon $at): Referral
    {
        $referral = Referral::create([
            'business_id' => $referrer->business_id,
            'referrer_membership_id' => $referrer->id,
            'referred_customer_id' => $friend->id,
            'redeem_token_hash' => OpaqueToken::hash($plain = OpaqueToken::generate()),
            'redeem_token_encrypted' => $plain,
            'state' => Referral::STATE_PENDING,
            'expires_at' => $at->copy()->addDays(30),
        ]);
        $referral->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        ReferralEvent::record('referral_created', 'customer', $referrer->business, $referral, null, $referrer);

        return $referral;
    }
}
