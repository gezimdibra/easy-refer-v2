<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\Reward;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The completed Overview (spec Section 5.1, Sprint 3): the
 * standing counters read the spec's definitions off live rows,
 * pending is never merged into a total, the chart's cumulative
 * line is the redeemed history, the top referrers are this
 * week's, and the checklist dismissal persists server-side and
 * resets from Settings.
 */
class DashboardDataTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    public function test_standing_counters_follow_the_spec_definitions(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550101', 'Ana', 'Ray'));
        [$second] = World::membership($business, World::customer('+12125550102', 'Ben', 'Fox'));

        // Two completed, one pending, one expired: completed reads
        // 2, pending reads 1, and nothing merges them.
        World::referral($membership, World::customer('+12125550111', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
        ]);
        World::referral($membership, World::customer('+12125550112', 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::referral($second, World::customer('+12125550113', 'Hal', 'Three'));
        World::referral($second, World::customer('+12125550114', 'Ida', 'Four'), [
            'state' => Referral::STATE_EXPIRED,
        ]);

        [$redeemed] = World::referral($membership, World::customer('+12125550115', 'Jay', 'Five'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(3),
        ]);
        World::reward($membership, $redeemed, ['state' => Reward::STATE_CLAIMED]);
        [$redeemedTwo] = World::referral($second, World::customer('+12125550116', 'Kim', 'Six'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(4),
        ]);
        World::reward($second, $redeemedTwo, ['state' => Reward::STATE_AVAILABLE]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Referrals');
        $response->assertSee('Pending referrals');
        $response->assertSee('Rewards issued');
        $response->assertSee('Rewards claimed');
        $response->assertSee('Promotional messages sent');
        $response->assertSee('Cumulative referred customers');
        $response->assertSee('View as table');

        $counters = $response->viewData('counters');
        $this->assertSame(4, $counters['completed_total']);
        $this->assertSame(1, $counters['pending_total']);
        $this->assertSame(2, $counters['total_customers']);
        $this->assertSame(2, $counters['rewards_issued']);
        $this->assertSame(1, $counters['rewards_claimed']);
        $this->assertSame(0, $counters['promo_sent']);

        $chart = $response->viewData('chart');
        $this->assertSame(4, end($chart)['total']);
    }

    public function test_dashboard_applies_expiry_and_vesting_on_read(): void
    {
        // Spec verifier finding 2: the counters must read
        // post-transition state even when no sweeper has run.
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550131', 'Ana', 'Ray'));

        [$stale] = World::referral($membership, World::customer('+12125550132', 'Fay', 'One'), [
            'expires_at' => now()->subHour(),
        ]);
        [$redeemed] = World::referral($membership, World::customer('+12125550133', 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$reward] = World::reward($membership, $redeemed, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();

        $counters = $response->viewData('counters');
        $this->assertSame(0, $counters['pending_total']);
        $tiles = $response->viewData('tiles');
        $this->assertSame(1, $tiles['rewards_waiting']);
        $this->assertSame(Referral::STATE_EXPIRED, $stale->fresh()->state);
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
    }

    public function test_chart_range_parameter_is_validated(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550121', 'Ana', 'Ray'));
        World::referral($membership, World::customer('+12125550122', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20),
        ]);

        $this->actingAs($owner)->get(route('dashboard', ['range' => '7']))->assertOk();
        $this->actingAs($owner)->get(route('dashboard', ['range' => 'bogus']))->assertOk();
    }

    public function test_checklist_dismissal_persists_and_resets_from_settings(): void
    {
        $business = World::business(['friend_reward_text' => null]);
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('dashboard'))->assertSee('Setup checklist');

        $this->actingAs($owner)->post(route('dashboard.checklist.dismiss'))->assertRedirect(route('dashboard'));

        // Phase 2 (G6): the dismissal lives in the Action
        // Center's dismissal domain at the checklist's current
        // fingerprint; the legacy checklist_dismissed_at column
        // is no longer written.
        $this->assertNull($business->fresh()->checklist_dismissed_at);
        $this->assertTrue(\App\Models\ActionCenterDismissal::query()
            ->where('business_id', $business->id)
            ->where('item_key', 'setup_checklist')
            ->exists());
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Setup checklist');
        $this->assertTrue(AuditLog::query()->where('action', 'business.checklist_dismissed')->exists());

        $this->actingAs($owner)->post(route('settings.checklist.reset'))->assertRedirect();
        $this->assertNull($business->fresh()->checklist_dismissed_at);
        $this->assertFalse(\App\Models\ActionCenterDismissal::query()
            ->where('business_id', $business->id)
            ->where('item_key', 'setup_checklist')
            ->exists());
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('Setup checklist');
    }

    public function test_value_panel_settings_link_points_at_the_settings_value_page(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('settings.value'), false);
        $response->assertDontSee('href="'.route('security').'"', false);
    }
}
