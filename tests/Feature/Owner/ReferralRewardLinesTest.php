<?php

namespace Tests\Feature\Owner;

use App\Models\Referral;
use App\Models\Reward;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RT-1 (owner correction, 2026-10-27): a referral carries TWO
 * rewards and the owner surfaces must name whose is whose.
 * The friend's reward is given at the visit (no wait); the
 * referrer's reward is the one "On its way" under the OD-3
 * vesting wait. The bare caption "Reward" beside a vesting
 * date read as the friend waiting; these pins hold the named
 * lines on the referral detail and the referrals list.
 */
class ReferralRewardLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_detail_names_both_rewards_and_their_timing(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550801', 'Dana', 'Whitfield'));
        [$referral] = World::referral($membership, World::customer('+12125550802', 'Chris', 'Test'), [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => Carbon::parse('2026-10-27 23:22:00', 'UTC'),
        ]);
        World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => Carbon::parse('2026-11-04 02:30:00', 'UTC'),
            'notification_state' => 'sent',
        ]);

        $html = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();

        // The friend's line: their name, the promised reward,
        // and the fact it was given at the visit.
        $this->assertStringContainsString('>Chris&#039;s reward</span>', $html);
        $this->assertStringContainsString('$10 off your first visit', $html);
        $this->assertStringContainsString('Given at the visit on Oct 27, 2026.', $html);
        // The referrer's line: their name on the vesting block.
        $this->assertStringContainsString('>Dana&#039;s reward</span>', $html);
        $this->assertStringContainsString('On its way', $html);
        $this->assertStringContainsString('Ready on Nov 3, 2026', $html);
        // The bare caption that caused the confusion is gone.
        $this->assertStringNotContainsString('>Reward</span>', $html);
    }

    public function test_referral_detail_pending_referral_promises_the_friend_reward_at_the_visit(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550811', 'Dana', 'Whitfield'));
        [$referral] = World::referral($membership, World::customer('+12125550812', 'Chris', 'Test'));

        $html = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();

        $this->assertStringContainsString('>Chris&#039;s reward</span>', $html);
        $this->assertStringContainsString('Given when they visit.', $html);
        // No referrer reward exists yet, so no vesting block.
        $this->assertStringNotContainsString('On its way', $html);
    }

    public function test_referral_detail_held_and_expired_friend_lines(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550821', 'Dana', 'Whitfield'));

        [$held] = World::referral($membership, World::customer('+12125550822', 'Leo', 'Test'), [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);
        $heldHtml = $this->actingAs($owner)->get(route('referrals.show', $held))->assertOk()->getContent();
        $this->assertStringContainsString('>Leo&#039;s reward</span>', $heldHtml);
        $this->assertStringContainsString('Waiting for review.', $heldHtml);

        [$expired] = World::referral($membership, World::customer('+12125550823', 'Ana', 'Test'), [
            'state' => Referral::STATE_EXPIRED,
        ]);
        $expiredHtml = $this->actingAs($owner)->get(route('referrals.show', $expired))->assertOk()->getContent();
        $this->assertStringContainsString('Not given. The visit window has ended.', $expiredHtml);
    }

    public function test_referrals_list_caption_names_the_referrer(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550831', 'Dana', 'Whitfield'));
        [$referral] = World::referral($membership, World::customer('+12125550832', 'Chris', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $referral);

        $html = $this->actingAs($owner)->get(route('referrals.index'))->assertOk()->getContent();

        // Both renders (mobile rows + desktop table) name the
        // referrer on the reward caption; the bare caption is
        // absent page-wide.
        $this->assertSame(2, substr_count($html, ">Dana's reward</span>"));
        $this->assertStringNotContainsString('>Reward</span>', $html);
    }
}
