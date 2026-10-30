<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * R4-U1 (redesign/r4-rewards-print, Surface 1 + 2 + 5): one state
 * language everywhere. The customer pill set and the staff band set
 * (copy Amendment 26, R4-STATE-01/02, PROPOSED) render from the one
 * FlowState component; state is never carried by color alone (every
 * pill/band pairs its fill with a text label); the reward text is
 * restored on the held/denied customer states; the held state gains
 * its closing line (R4-STATE-03); the expired state's exit is a real
 * secondary button.
 */
class FlowStateLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function claimSetup(array $rewardOverrides): array
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [$reward, $claimToken] = World::reward($membership, $referral, $rewardOverrides);

        return [$business, $reward, $claimToken];
    }

    public function test_claim_ready_carries_the_ready_pill(): void
    {
        [, , $claimToken] = $this->claimSetup(['state' => Reward::STATE_AVAILABLE]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('state-pill', false)
            ->assertSee('>Ready<', false);
    }

    public function test_claim_waiting_carries_the_on_its_way_pill(): void
    {
        [, , $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(10),
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('>On its way<', false);
    }

    public function test_claim_held_shows_in_review_pill_reward_and_closing_line(): void
    {
        [, $reward, $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_AVAILABLE, 'hold' => true, 'held_at' => now(),
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('>In review<', false)
            ->assertSee($reward->reward_text_snapshot)
            ->assertSee('Nothing for you to do. We will text you when it is ready, unless you have opted out of texts. You can also check this page.');
    }

    public function test_claim_denied_shows_not_approved_pill_and_the_reward(): void
    {
        [, $reward, $claimToken] = $this->claimSetup(['state' => Reward::STATE_DENIED]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('>Not approved<', false)
            ->assertSee($reward->reward_text_snapshot);
    }

    public function test_redeem_show_carries_the_ready_pill(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend);

        $this->get(route('refer.redeem.page', ['token' => $token]))
            ->assertOk()
            ->assertSee('>Ready<', false);
    }

    public function test_redeem_expired_shows_expired_pill_and_a_button_exit(): void
    {
        $business = World::business();
        World::qr($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend, ['expires_at' => now()->subDay()]);

        $response = $this->get(route('refer.redeem.page', ['token' => $token]))->assertOk();
        $response->assertSee('>Expired<', false);
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="[^"]*btn btn-secondary[^"]*"[^>]*>Back to /',
            $response->getContent(),
            'the expired exit is a real secondary button, not a text link',
        );
    }

    public function test_redeem_page_has_no_held_state_for_the_friend(): void
    {
        // Option B (2026-10-28): the friend's redeem page held
        // variant is RETIRED — a pending referral renders the
        // show state even when seeded with the legacy hold shape
        // (no live path creates one; Gate 1 normalization converts
        // any that remain). The "In review" pill and the
        // check-back-after-review line survive only on the
        // REFERRER's claim/share surfaces for a held reward.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        $this->get(route('refer.redeem.page', ['token' => $token]))
            ->assertOk()
            ->assertSee('Show this code to a staff member')
            ->assertDontSee('>In review<', false)
            ->assertDontSee('Nothing for you to do. Check this page again after the business has reviewed it.')
            ->assertDontSee('Held for review');
    }

    public function test_show_screens_stage_the_code_at_248_on_one_plate(): void
    {
        // R4-U2: both show screens share one grammar: prize headline,
        // the code at 248px on the single white .qr-stage plate (no
        // .well double frame, no bordered 220px img).
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend);

        $redeem = $this->get(route('refer.redeem.page', ['token' => $token]))->assertOk();
        $redeem->assertSee($business->friendRewardHeadline())
            ->assertSee('qr-stage', false)
            ->assertSee('width="248"', false)
            ->assertDontSee('width="220"', false);
    }

    public function test_claim_show_screen_stages_the_code_the_same_way(): void
    {
        [, $reward, $claimToken] = $this->claimSetup(['state' => Reward::STATE_AVAILABLE]);
        $claim = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk();
        $claim->assertSee($reward->reward_text_snapshot)
            ->assertSee('qr-stage', false)
            ->assertSee('width="248"', false)
            ->assertDontSee('width="220"', false);
    }

    public function test_staff_already_redeemed_opens_with_the_band(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('state-band', false)
            ->assertSee('Already redeemed', false);
    }

    public function test_staff_held_opens_with_the_owner_review_band(): void
    {
        // Option B (2026-10-28): the staff held band survives for
        // REWARD tokens only (the referrer's claim waits for
        // review); a referral token no longer renders it.
        [, , $claimToken] = $this->claimSetup([
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Owner review needed', false);
    }

    public function test_staff_confirm_opens_with_the_verdict_band_and_facts_well(): void
    {
        // R4-U3 (Surface 4 pick C): band first, reward headline,
        // For line, and the Business / Sent by facts well.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('state-band', false)
            ->assertSee('Ready to redeem', false)
            ->assertSee('Business', false)
            ->assertSee('Sent by', false)
            ->assertSee('Reg')
            ->assertSee('Mark as Redeemed');
    }

    public function test_staff_generic_state_carries_no_band(): void
    {
        // The generic 404 stays information-identical: no band, no
        // state vocabulary leaks into it.
        $this->get(route('redeem.show', ['token' => str_repeat('c', 43)]))
            ->assertNotFound()
            ->assertDontSee('state-band', false)
            ->assertDontSee('Already redeemed', false);
    }
}
