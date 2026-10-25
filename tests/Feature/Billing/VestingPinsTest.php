<?php

namespace Tests\Feature\Billing;

use App\Models\Reward;
use App\Services\Referral\RedemptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Reward vesting pins (Phase 4 coverage map row 5, OD-3, BILL-43):
 * the hold boundary is exact (at available_at the reward vests,
 * one second before it does not), vesting never touches terminal
 * states, the set-level scope is per business, and the hold length
 * comes from configuration with the 7 to 14 day range enforced at
 * mint time (out-of-range values fall back to the 14-day default).
 * Model and service level, no HTTP.
 */
class VestingPinsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: \App\Models\Business, 1: \App\Models\Membership}
     */
    private function world(): array
    {
        $business = World::business();
        $referrer = World::customer('+12125550997', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return [$business, $membership];
    }

    private function pendingReward($membership, $referral, Carbon $availableAt): Reward
    {
        [$reward] = World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => $availableAt,
            'notification_state' => 'queued',
        ]);

        return $reward;
    }

    public function test_reward_vests_at_exactly_available_at(): void
    {
        [, $membership] = $this->world();
        $friend = World::customer('+12125550101', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $reward = $this->pendingReward($membership, $referral, Carbon::parse('2026-06-15 12:00:00'));

        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        // isFuture is strict: at the exact vesting instant the
        // hold has elapsed and the reward is available.
        $this->assertTrue($reward->applyVesting());
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
    }

    public function test_reward_does_not_vest_one_second_before_available_at(): void
    {
        [, $membership] = $this->world();
        $friend = World::customer('+12125550102', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $reward = $this->pendingReward($membership, $referral, Carbon::parse('2026-06-15 12:00:00'));

        $this->travelTo(Carbon::parse('2026-06-15 11:59:59'));

        $this->assertFalse($reward->applyVesting());
        $this->assertSame(Reward::STATE_PENDING_VESTING, $reward->fresh()->state);
    }

    public function test_vesting_never_transitions_terminal_or_claimed_rewards(): void
    {
        [, $membership] = $this->world();

        foreach ([Reward::STATE_AVAILABLE, Reward::STATE_CLAIMED, Reward::STATE_DENIED, Reward::STATE_EXPIRED] as $i => $state) {
            $friend = World::customer('+1212555011'.$i, 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            [$reward] = World::reward($membership, $referral, [
                'state' => $state,
                'available_at' => now()->subDay(),
            ]);

            $this->assertFalse($reward->applyVesting(), "state {$state} must not vest");
            $this->assertSame($state, $reward->fresh()->state);
        }
    }

    public function test_set_level_vesting_is_scoped_to_one_business(): void
    {
        [$business, $membership] = $this->world();
        [$otherBusiness, $otherMembership] = $this->world();

        $friendA = World::customer('+12125550121', 'Fri', 'End');
        [$referralA] = World::referral($membership, $friendA);
        $dueA = $this->pendingReward($membership, $referralA, now()->subHour());

        $friendB = World::customer('+12125550122', 'Fri', 'End');
        [$referralB] = World::referral($membership, $friendB);
        $notDue = $this->pendingReward($membership, $referralB, now()->addDay());

        $friendC = World::customer('+12125550123', 'Fri', 'End');
        [$referralC] = World::referral($otherMembership, $friendC);
        $dueOther = $this->pendingReward($otherMembership, $referralC, now()->subHour());

        Reward::applyVestingForBusiness($business);

        $this->assertSame(Reward::STATE_AVAILABLE, $dueA->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $notDue->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $dueOther->fresh()->state);

        Reward::applyVestingForBusiness($otherBusiness);
        $this->assertSame(Reward::STATE_AVAILABLE, $dueOther->fresh()->state);
    }

    public function test_vesting_configuration_carries_the_default_and_range(): void
    {
        $this->assertSame(14, (int) config('referrals.vesting_days_default'));
        $this->assertSame(7, (int) config('referrals.vesting_days_min'));
        $this->assertSame(14, (int) config('referrals.vesting_days_max'));
        $this->assertGreaterThanOrEqual(
            (int) config('referrals.vesting_days_min'),
            (int) config('referrals.vesting_days_default'),
        );
        $this->assertLessThanOrEqual(
            (int) config('referrals.vesting_days_max'),
            (int) config('referrals.vesting_days_default'),
        );
    }

    /**
     * BILL-43 at the mint: the business's configured hold (7 or
     * 14) lands on available_at exactly; a business value outside
     * the range falls back to the config default (14) rather than
     * minting an out-of-range reward. (GAP 2 moved the source of
     * the value from the platform config to the per-business
     * setting; the range discipline is unchanged.)
     */
    public function test_mint_hold_length_follows_configuration_within_the_range(): void
    {
        foreach ([7 => 7, 14 => 14, 6 => 14, 15 => 14, 20 => 14] as $configured => $expectedDays) {
            [$business, $membership] = $this->world();
            $business->forceFill(['reward_vesting_days' => $configured])->save();
            $friend = World::customer('+1212555013'.$configured, 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);

            $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
            $this->assertSame('redeemed', $result['outcome']);

            $reward = Reward::query()->where('referral_id', $referral->id)->firstOrFail();
            $delta = abs($reward->available_at->unix() - $referral->fresh()->redeemed_at->unix());
            $this->assertEqualsWithDelta(
                $expectedDays * 86400,
                $delta,
                120,
                "configured {$configured} days must mint a {$expectedDays}-day hold",
            );
        }
    }

    /**
     * GAP 2 (design 2.6 test 4): two businesses mint at their own
     * setting on the same day.
     */
    public function test_mint_uses_the_business_vesting_days(): void
    {
        foreach ([7, 14] as $days) {
            [$business, $membership] = $this->world();
            $business->forceFill(['reward_vesting_days' => $days])->save();
            $friend = World::customer('+1212555015'.$days, 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);

            app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

            $reward = Reward::query()->where('referral_id', $referral->id)->firstOrFail();
            $delta = abs($reward->available_at->unix() - $referral->fresh()->redeemed_at->unix());
            $this->assertEqualsWithDelta($days * 86400, $delta, 120);
        }
    }

    /**
     * GAP 2 (design 2.6 test 6): the grandfathering rule. A
     * settings change never moves a reward already minted, in
     * either direction; the next mint uses the new value.
     */
    public function test_changing_vesting_days_does_not_move_existing_rewards(): void
    {
        [$business, $membership] = $this->world();
        $business->forceFill(['reward_vesting_days' => 14])->save();
        $friend = World::customer('+12125550161', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $reward = Reward::query()->where('referral_id', $referral->id)->firstOrFail();
        $stamped = $reward->available_at->toIso8601String();

        $business->forceFill(['reward_vesting_days' => 7])->save();
        $this->assertSame($stamped, $reward->fresh()->available_at->toIso8601String());

        $friend2 = World::customer('+12125550162', 'Fri', 'End');
        [$referral2] = World::referral($membership, $friend2);
        app(RedemptionService::class)->redeemReferral($referral2, null, '127.0.0.1');
        $reward2 = Reward::query()->where('referral_id', $referral2->id)->firstOrFail();
        $delta = abs($reward2->available_at->unix() - $referral2->fresh()->redeemed_at->unix());
        $this->assertEqualsWithDelta(7 * 86400, $delta, 120);
    }

    /**
     * GAP 2 (design 2.6 test 7): the setting governs REFERRER
     * rewards only. The friend's welcome reward is the redemption
     * itself (no separate vesting row exists for it), and it
     * completes immediately at any setting value.
     */
    public function test_friend_reward_is_not_vested_by_this_setting(): void
    {
        foreach ([7, 14] as $days) {
            [$business, $membership] = $this->world();
            $business->forceFill(['reward_vesting_days' => $days])->save();
            $friend = World::customer('+1212555017'.$days, 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);

            $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

            $this->assertSame('redeemed', $result['outcome']);
            $this->assertNotNull($referral->fresh()->redeemed_at);
            // The only reward minted is the referrer's; nothing
            // friend-side waits on the setting.
            $this->assertSame(1, Reward::query()->where('business_id', $business->id)->count());
            $this->assertSame($membership->id, Reward::query()->where('referral_id', $referral->id)->firstOrFail()->membership_id);
        }
    }
}
