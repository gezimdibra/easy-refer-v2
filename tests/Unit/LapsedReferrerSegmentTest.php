<?php

namespace Tests\Unit;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Campaigns\LapsedReferrerSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-03 (Amendment 46): the lapsed-referrer segment truth.
 * Proven = at least one qualifying redeemed referral (never
 * fraud-denied, not under review); lapsed = the last referral
 * CREATED through their link (any state) is >= 45 days ago;
 * no live pending referral; share token active.
 */
class LapsedReferrerSegmentTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 5100;

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    private function referral(Membership $referrer, string $state, Carbon $createdAt, array $opts = []): Referral
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$referral] = World::referral($referrer, World::customer($phone));
        $referral->forceFill([
            'state' => $state,
            'created_at' => $createdAt,
            'redeemed_at' => $state === Referral::STATE_REDEEMED ? $createdAt->copy()->addDays(2) : null,
            'expires_at' => $opts['expires_at'] ?? $createdAt->copy()->addDays(30),
        ])->save();

        if (($opts['coverage'] ?? null) !== null || ($opts['reward'] ?? null) !== null) {
            BillingEvent::create([
                'business_id' => $referrer->business_id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => $opts['coverage'] ?? 'billable',
            ]);
        }
        if (($opts['reward'] ?? null) !== null) {
            [$reward] = World::reward($referrer, $referral);
            $reward->forceFill($opts['reward'])->save();
        }

        return $referral->fresh();
    }

    private function proven(Membership $m, int $daysAgo = 60): Referral
    {
        return $this->referral($m, Referral::STATE_REDEEMED, now()->subDays($daysAgo), ['coverage' => 'billable']);
    }

    public function test_redeemed_clean_referrer_lapsed_at_45_days_is_in(): void
    {
        $business = World::business();
        $m = $this->member($business);
        $this->proven($m, 60);

        $this->assertContains($m->id, LapsedReferrerSegment::ids($business));
    }

    public function test_boundary_exactly_45_days_is_in_one_second_inside_is_out(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00');
        $business = World::business();
        $in = $this->member($business);
        $this->referral($in, Referral::STATE_REDEEMED, now()->subDays(45), ['coverage' => 'billable']);
        $out = $this->member($business);
        $this->referral($out, Referral::STATE_REDEEMED, now()->subDays(45)->addSecond(), ['coverage' => 'billable']);

        $ids = LapsedReferrerSegment::ids($business);
        $this->assertContains($in->id, $ids);
        $this->assertNotContains($out->id, $ids);
        Carbon::setTestNow();
    }

    public function test_pending_only_expired_only_and_voided_only_never_qualify(): void
    {
        $business = World::business();
        foreach ([Referral::STATE_PENDING, Referral::STATE_EXPIRED, Referral::STATE_VOIDED] as $state) {
            $m = $this->member($business);
            $this->referral($m, $state, now()->subDays(90), ['expires_at' => now()->subDays(60)]);
        }

        $this->assertSame([], LapsedReferrerSegment::ids($business));
    }

    public function test_denied_only_and_held_only_never_qualify(): void
    {
        $business = World::business();
        $denied = $this->member($business);
        $this->referral($denied, Referral::STATE_REDEEMED, now()->subDays(90), [
            'coverage' => 'fraud_denied', 'reward' => ['state' => Reward::STATE_DENIED],
        ]);
        $held = $this->member($business);
        $this->referral($held, Referral::STATE_REDEEMED, now()->subDays(90), [
            'coverage' => 'held', 'reward' => ['state' => Reward::STATE_PENDING_VESTING, 'hold' => true],
        ]);
        $heldRewardOnly = $this->member($business);
        $this->referral($heldRewardOnly, Referral::STATE_REDEEMED, now()->subDays(90), [
            'coverage' => 'billable', 'reward' => ['state' => Reward::STATE_PENDING_VESTING, 'hold' => true],
        ]);

        $this->assertSame([], LapsedReferrerSegment::ids($business));
    }

    public function test_mixed_history_qualifies_on_the_clean_row(): void
    {
        $business = World::business();
        $m = $this->member($business);
        $this->referral($m, Referral::STATE_REDEEMED, now()->subDays(100), [
            'coverage' => 'fraud_denied', 'reward' => ['state' => Reward::STATE_DENIED],
        ]);
        $this->proven($m, 90);

        $this->assertContains($m->id, LapsedReferrerSegment::ids($business));
    }

    public function test_recent_referral_of_any_state_defeats_lapse(): void
    {
        $business = World::business();
        $m = $this->member($business);
        $this->proven($m, 90);
        // A fresh (later expired) referral 10 days ago: they
        // referred 10 days ago, whatever became of it.
        $this->referral($m, Referral::STATE_EXPIRED, now()->subDays(10), ['expires_at' => now()->subDays(5)]);

        $this->assertNotContains($m->id, LapsedReferrerSegment::ids($business));
    }

    public function test_live_pending_referral_excludes_even_when_clock_passes(): void
    {
        $business = World::business();
        $m = $this->member($business);
        $this->proven($m, 90);
        // An old pending referral whose window is still open
        // (a long redemption window business): a visit is in
        // flight, so the member is out despite the old clock.
        $this->referral($m, Referral::STATE_PENDING, now()->subDays(50), ['expires_at' => now()->addDays(10)]);

        $this->assertNotContains($m->id, LapsedReferrerSegment::ids($business));
    }

    public function test_revoked_token_excludes_and_reactivation_reenters(): void
    {
        $business = World::business();
        $m = $this->member($business);
        $this->proven($m, 60);
        $m->forceFill(['share_token_state' => 'revoked'])->save();

        $this->assertNotContains($m->id, LapsedReferrerSegment::ids($business->fresh()));

        $m->forceFill(['share_token_state' => 'active'])->save();
        $this->assertContains($m->id, LapsedReferrerSegment::ids($business->fresh()));
    }

    public function test_refunded_edge_is_governed_by_the_reward_legs(): void
    {
        // Condition 5 (two-key ruling): coverage 'refunded'
        // is not itself an exclusion — the composed rule
        // decides through the reward legs. At clawback a
        // pending-vesting reward is DENIED (out) and a
        // vested/claimed reward is HELD for review (out
        // while held); a reward the owner RELEASED after
        // review (hold cleared, not denied) leaves the
        // visit's qualification standing (in).
        $business = World::business();

        $denied = $this->member($business);
        $this->referral($denied, Referral::STATE_REDEEMED, now()->subDays(90), [
            'coverage' => 'refunded', 'reward' => ['state' => Reward::STATE_DENIED, 'hold' => false],
        ]);

        $held = $this->member($business);
        $this->referral($held, Referral::STATE_REDEEMED, now()->subDays(90), [
            'coverage' => 'refunded', 'reward' => ['state' => Reward::STATE_AVAILABLE, 'hold' => true],
        ]);

        $released = $this->member($business);
        $this->referral($released, Referral::STATE_REDEEMED, now()->subDays(90), [
            'coverage' => 'refunded', 'reward' => ['state' => Reward::STATE_AVAILABLE, 'hold' => false],
        ]);

        $ids = LapsedReferrerSegment::ids($business);
        $this->assertNotContains($denied->id, $ids);
        $this->assertNotContains($held->id, $ids);
        $this->assertContains($released->id, $ids);
    }

    public function test_removed_member_is_not_in_the_segment(): void
    {
        $business = World::business();
        $m = $this->member($business);
        $this->proven($m, 60);
        $m->forceFill(['removed_at' => now()])->save();

        $this->assertNotContains($m->id, LapsedReferrerSegment::ids($business));
    }
}
