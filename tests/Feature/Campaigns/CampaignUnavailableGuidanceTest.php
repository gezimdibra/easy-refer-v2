<?php

namespace Tests\Feature\Campaigns;

use App\Services\Contracts\MarketingSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-CAMP-02 (campaigns dead end): when campaigns are
 * unavailable AND the business's own program is not active,
 * the unavailable state explains the blocker and points at
 * the fix — "Campaigns open once your program is active."
 * with the inventory Add a card action into Billing (the
 * QR-INACTIVE-01a pattern). When the business IS active and
 * only the platform gateway is unconfigured, the state stays
 * exactly today's single line: nothing the owner can do
 * about it, so nothing is asked of them.
 */
class CampaignUnavailableGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private function unbindGateway(): void
    {
        // No fake bound: the test environment has no Twilio
        // configuration, so the null gateway is bound.
        $this->app->forgetInstance(MarketingSmsGateway::class);
    }

    public function test_inactive_program_gains_the_explanation_and_the_card_action(): void
    {
        $this->unbindGateway();
        // RE-DERIVED (activation redesign, census C-26,
        // 2026-10-30): billing 'none' alone is LIVE now (the
        // allowance), so the inactive program here is the
        // allowance-exhausted one — the state whose panel
        // branch carries the card action.
        $business = World::business(['billing_status' => 'none']);
        $cgReferrer = World::customer('+12125551101', 'Reg', 'Ular');
        [$cgMembership] = World::membership($business, $cgReferrer);
        for ($i = 0; $i < 5; $i++) {
            $cgFriend = World::customer('+1212555111'.(string) $i, 'Fri', 'End');
            [$cgReferral] = World::referral($cgMembership, $cgFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $cgReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
        $owner = World::owner($business);

        foreach ([route('campaigns.index'), route('campaigns.create')] as $url) {
            $response = $this->actingAs($owner)->get($url);

            $response->assertOk();
            $response->assertSee('Text campaigns are not available right now.');
            $response->assertSee('Campaigns open once your program is active.');
            $response->assertSee('Add a card');
            $response->assertSee('href="'.route('settings.billing').'#card"', false);
        }
    }

    public function test_active_program_with_unconfigured_gateway_keeps_the_single_line(): void
    {
        $this->unbindGateway();
        $business = World::business();
        $this->assertTrue($business->flowIsActive());
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('campaigns.index'));

        $response->assertOk();
        $response->assertSee('Text campaigns are not available right now.');
        $response->assertDontSee('Campaigns open once your program is active.');
        $response->assertDontSee('Add a card');
    }
}
