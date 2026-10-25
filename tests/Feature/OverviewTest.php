<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The owner Overview and QR page (spec Section 5, design amendment
 * Section 6.4.1): the zero-referral retention state and the
 * populated state whose Value panel reconciles with the billing
 * event rows.
 */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    public function test_zero_state_overview(): void
    {
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Welcome to Easy Refer, '.$business->name.'.', false)
            // The greeting's designed second sentence (F-09d).
            ->assertSee('Your program is live. The first step is getting your code where customers can see it.')
            // The action card's designed line and text actions (F-09c).
            ->assertSee('Posters at the register get scanned. Posters in a drawer do not.')
            ->assertSee('Download the on-screen poster')
            ->assertSee('See how redemption works')
            ->assertSee('Print your QR code')
            // PACK-00: the starter pack card shows while rewards
            // hold defaults, with the type's packs and cost notes.
            ->assertSee('Start from a pack owners like you use')
            ->assertSee('You can edit both rewards after picking.')
            ->assertSee('10 percent off your first visit')
            ->assertSee('Typical cost: The safe default pair.')
            ->assertSee('Use this pack')
            // The invented defaults editor is gone (F-07).
            ->assertDontSee('Your starter rewards')
            ->assertDontSee('popular defaults')
            ->assertDontSee('$5 off')
            // Honest zeros, the designed tile set (F-09a), and
            // the standing counters suppressed in the zero state.
            ->assertSee('Scans today')
            ->assertSee('Completed referrals today')
            // The rewards-waiting tile renders at zero too since
            // the Sprint 3 fix wave (UI F-08): a stable tile set
            // in every state, and the tile links to the list.
            ->assertSee('Rewards waiting to be claimed')
            // The AUTH-SEC-03 checklist: four items, a progress
            // line (F-09b).
            ->assertSee('1 of 4 done')
            ->assertSee('Add a card (required to activate your QR code)')
            // The Value panel's zero variant (VAL-01, VAL-03): no
            // estimate, no illustrative numbers.
            ->assertSee('What Easy Refer brought you')
            ->assertSee('After your first visit counts, this panel shows what Easy Refer brought you')
            ->assertSee('Estimates use your average ticket of $50.00')
            ->assertDontSee('Estimated first-visit sales');
    }

    public function test_starter_pack_prefills_settings_rewards_without_saving(): void
    {
        // Spec Section 5.5 and design 6.4.1 item 3 (Sprint 3
        // spec verifier finding 13): choosing a pack PREFILLS
        // the two reward fields and lands the owner in Settings
        // Rewards with the values in place and editable. Nothing
        // is saved until that form is submitted.
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);
        $owner = World::owner($business);

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertSee('Use this pack');

        $prefillUrl = route('settings.rewards', [
            'pack_friend' => '10 percent off your first visit',
            'pack_referrer' => '10 percent off your next visit',
        ]);
        $dashboard->assertSee('pack_friend=', false);

        $this->actingAs($owner)->get($prefillUrl)
            ->assertOk()
            ->assertSee('value="10 percent off your first visit"', false)
            ->assertSee('value="10 percent off your next visit"', false);

        // Nothing was written by choosing the pack.
        $business->refresh();
        $this->assertNull($business->friend_reward_text);
        $this->assertNull($business->referrer_reward_text);

        // Submitting the settings form saves the pair, and the
        // card leaves the Overview.
        $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => '10 percent off your first visit',
            'referrer_reward_text' => '10 percent off your next visit',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 14,
        ])->assertSessionHas('status', 'Rewards saved.');

        $business->refresh();
        $this->assertSame('10 percent off your first visit', $business->friend_reward_text);
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertDontSee('Start from a pack owners like you use');
    }

    public function test_populated_overview_value_panel_reconciles(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        // Two verified visits this month through the real path.
        foreach (['+12125550777', '+12125550666'] as $phone) {
            $friend = World::customer($phone, 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        }

        $this->assertSame(2, BillingEvent::count());

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk()
            // VAL-02 verbatim, with correct number agreement.
            ->assertSee('2 visits counted this month')
            // 2 visits x $50 average ticket = $100.00 estimated.
            ->assertSee('About $100.00 in first-visit sales (estimate)')
            // First five visits are free: fees are exactly zero and
            // they reconcile with the billing rows.
            ->assertSee('Fees so far this month: $0.00')
            ->assertDontSee('After your first visit counts');
    }

    public function test_qr_page_shows_the_live_code_and_download_records(): void
    {
        $business = World::business();
        World::qr($business);
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            ->assertSee('Your QR code')
            ->assertSee('/refer/');

        // T2-F3: the download now serves the file itself (200 with
        // an attachment disposition), pinned in QrDownloadTest.
        $this->actingAs($owner)->post(route('owner.qr.download'))->assertOk();
        $this->assertDatabaseHas('referral_events', ['business_id' => $business->id, 'event_type' => 'poster_downloaded']);
    }

    public function test_value_panel_counts_billed_visits_past_the_free_allowance(): void
    {
        // Six visits in one month on the launch plan: the first
        // five are free-credit, the sixth bills $2.00, and the
        // panel's fee figure is exactly that.
        $business = World::business(['daily_referral_cap' => 25]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        for ($i = 0; $i < 6; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) (700 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        }

        $valuation = app(\App\Services\Billing\VisitValuation::class)->settle($business);
        $this->assertSame(6, $valuation['verified_visits']);
        $this->assertSame(200, $valuation['fees_cents']);
        $coverages = array_column($valuation['settled'], 'coverage');
        $this->assertSame(['free_credit', 'free_credit', 'free_credit', 'free_credit', 'free_credit', 'billable'], array_values($coverages));
    }

    public function test_monthly_cap_settles_at_49_billed_visits(): void
    {
        // BILL-40: the cents cap governs; a month never bills more
        // than 49 visits ($98) no matter how many redeem.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        for ($i = 0; $i < 60; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
            ]);
        }

        $valuation = app(\App\Services\Billing\VisitValuation::class)->settle($business);
        $this->assertSame(60, $valuation['verified_visits']);
        $this->assertSame(9800, $valuation['fees_cents']);
    }

    public function test_settle_recomputes_after_forget_settlements_when_events_are_written_mid_resolution(): void
    {
        // The memo contract: one instance's settlement stays
        // valid only while the caller writes nothing. A caller
        // that records billing events after settling (the shape
        // the Phase 4 nightly batch takes when it persists
        // coverage) invalidates, and the same instance then
        // settles the fresh rows instead of the stale ones.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $service = app(\App\Services\Billing\VisitValuation::class);

        $this->assertSame(0, $service->settle($business)['verified_visits']);

        BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => 200,
        ]);

        $service->forgetSettlements();

        $this->assertSame(1, $service->settle($business)['verified_visits']);
    }
}
