<?php

namespace Tests\Feature\Owner;

use App\Models\Reward;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-VEST-01 (state visibility): a reward that renders as
 * "On its way" also says when it lands — "Ready on {date}"
 * from the stored vesting timestamp, in the business's local
 * time. Ready and claimed rewards gain nothing; a held reward
 * (whose pill reads "Held") gains nothing.
 */
class VestingReadyDateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A redeemed referral whose reward is vesting until
     * 2026-11-04 02:30 UTC — Nov 3 in the business's
     * America/New_York time, so the local date is the pin.
     *
     * @return array{business: \App\Models\Business, owner: \App\Models\User, membership: \App\Models\Membership, referral: \App\Models\Referral, reward: Reward}
     */
    private function vestingWorld(array $rewardOverrides = []): array
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550984'));
        [$referral] = World::referral($membership, World::customer('+12125550112'), [
            'state' => \App\Models\Referral::STATE_REDEEMED,
            'redeemed_at' => now()->subDays(5),
        ]);
        [$reward] = World::reward($membership, $referral, array_merge([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => Carbon::parse('2026-11-04 02:30:00', 'UTC'),
            'notification_state' => 'sent',
        ], $rewardOverrides));

        return compact('business', 'owner', 'membership', 'referral', 'reward');
    }

    public function test_rewards_list_shows_the_ready_date_for_a_vesting_reward(): void
    {
        ['owner' => $owner] = $this->vestingWorld();

        $response = $this->actingAs($owner)->get(route('rewards.index'));

        $response->assertOk();
        $response->assertSee('On its way');
        $response->assertSee('Ready on Nov 3, 2026');
    }

    public function test_referral_detail_and_customer_detail_show_the_ready_date(): void
    {
        ['owner' => $owner, 'membership' => $membership, 'referral' => $referral] = $this->vestingWorld();

        $this->actingAs($owner)->get(route('referrals.show', $referral))
            ->assertOk()
            ->assertSee('Ready on Nov 3, 2026');

        $this->actingAs($owner)->get(route('customers.show', $membership))
            ->assertOk()
            ->assertSee('Ready on Nov 3, 2026');
    }

    public function test_ready_claimed_and_held_rewards_gain_no_ready_date(): void
    {
        ['owner' => $owner, 'business' => $business, 'membership' => $membership] = $this->vestingWorld();

        [$readyReferral] = World::referral($membership, World::customer('+12125550113'));
        World::reward($membership, $readyReferral, ['state' => Reward::STATE_AVAILABLE, 'available_at' => now()->subDay()]);

        [$heldReferral] = World::referral($membership, World::customer('+12125550114'));
        World::reward($membership, $heldReferral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => Carbon::parse('2026-11-04 02:30:00', 'UTC'),
            'hold' => true,
        ]);

        $response = $this->actingAs($owner)->get(route('rewards.index', ['status' => 'all']));

        $response->assertOk();
        // All three rows render: the vesting one, the ready
        // one, and the held one (pill reads Held).
        $response->assertSee('Ready to claim');
        $response->assertSee('Held');
        // Exactly one reward on the page carries the Ready-on
        // line: the vesting, unheld one. The available reward
        // shows its own label and the held one reads Held;
        // neither gains it. The R1 redesign renders the list
        // twice (mobile rows + desktop table), so the one
        // qualifying line appears once per render: 2 in the HTML.
        $this->assertSame(2, substr_count($response->getContent(), 'Ready on Nov 3, 2026'));
    }
}
