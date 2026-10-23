<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Jobs\SendRewardReadySms;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Referral;
use App\Models\ReferralEvent;
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
 * Redemption (spec Section 6): the staff scan-and-confirm screens,
 * the state-guarded transitions, nonce discipline, holds, audit,
 * claim vesting, and the concurrency contract. Test names map to
 * the RDM and CONC rows of test-matrix.md.
 */
class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsGateway $sms;

    private FakePhoneLookupGateway $lookup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->lookup = new FakePhoneLookupGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, $this->lookup);
        Mail::fake();
    }

    /**
     * Drive the staff screen: GET returns the page; extract the
     * per-render nonce from its form for the confirm POST.
     */
    private function staffNonce(string $token): string
    {
        $response = $this->get(route('redeem.show', ['token' => $token]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'staff screen carries a nonce');

        return $m[1];
    }

    private function pendingReferral(Business $business, array $overrides = []): array
    {
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');

        return World::referral($membership, $friend, $overrides);
    }

    public function test_staff_screen_shows_the_friend_referrer_and_one_confirm_button(): void
    {
        $business = World::business();
        [, $token] = $this->pendingReferral($business);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Fri')
            ->assertDontSee('Fri End')
            ->assertDontSee('Referred by')
            ->assertDontSee('Ular')
            ->assertSee('Mark as Redeemed')
            ->assertDontSee('PIN');
    }

    public function test_confirm_redeems_once_and_writes_the_full_record(): void
    {
        $business = World::business();
        [$referral, $token] = $this->pendingReferral($business);

        $nonce = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $token]));

        $referral->refresh();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->state);
        $this->assertSame('unattributed', $referral->attribution_state);
        $this->assertNotNull($referral->redeemed_at);

        // Exactly one billing event, at the plan price.
        $this->assertSame(1, BillingEvent::count());
        $this->assertSame(200, BillingEvent::first()->amount_cents);

        // The referrer's reward minted, pending vesting, snapshot
        // text, 14-day default (OD-3).
        $reward = Reward::query()->firstOrFail();
        $this->assertSame(Reward::STATE_PENDING_VESTING, $reward->state);
        $this->assertSame('$10 off', $reward->reward_text_snapshot);
        $this->assertEqualsWithDelta(14 * 86400, abs($reward->available_at->unix() - $referral->redeemed_at->unix()), 120);

        // Audit: the redemption event carries the row ID, never the
        // token (RDM-25), and the actor is the counter.
        $event = ReferralEvent::query()->where('event_type', 'redemption')->firstOrFail();
        $this->assertSame('counter', $event->actor);
        $this->assertSame($referral->id, $event->referral_id);
        $this->assertStringNotContainsString($token, json_encode(ReferralEvent::all()->toArray()));

        // E7 fired and the first-visit alert was claimed.
        $this->assertDatabaseHas('attribution_events', ['business_id' => $business->id, 'event_name' => 'first_verified_visit']);
        $this->assertDatabaseHas('business_alerts', ['business_id' => $business->id, 'kind' => 'first_visit']);
    }

    public function test_double_submit_produces_exactly_one_transition(): void
    {
        // CONC-02 / RDM-15: two confirm POSTs (each with its own
        // fresh render nonce) mint one reward and one billing row.
        $business = World::business();
        [, $token] = $this->pendingReferral($business);

        $first = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $first])->assertRedirect();

        // The landing right after the press is the Section 3.5
        // success flood (F-02): the reward, the business, the
        // confirmation timestamp, and the counter line.
        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Show this screen if anyone asks.')
            ->assertSee('Confirmed ')
            ->assertDontSee('Already redeemed');

        // A later scan renders the resume state, no button at all.
        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Already redeemed')
            ->assertDontSee('Mark as Redeemed');

        $this->assertSame(1, BillingEvent::count());
        $this->assertSame(1, Reward::count());
        $this->assertSame(1, ReferralEvent::query()->where('event_type', 'redemption')->count());
    }

    public function test_stale_instance_cannot_redeem_twice(): void
    {
        // The service re-reads state under the row lock, so a caller
        // holding a pre-transition copy (the parallel-request case)
        // gets 'already', not a second transition.
        $business = World::business();
        [$referral, ] = $this->pendingReferral($business);
        $stale = Referral::query()->find($referral->id);

        $service = app(RedemptionService::class);
        $first = $service->redeemReferral($referral, null, '127.0.0.1');
        $second = $service->redeemReferral($stale, null, '127.0.0.1');

        $this->assertSame('redeemed', $first['outcome']);
        $this->assertSame('already', $second['outcome']);
        $this->assertSame(1, BillingEvent::count());
        $this->assertSame(1, Reward::count());
    }

    public function test_nonce_reuse_is_refused(): void
    {
        $business = World::business();
        [$referral, $token] = $this->pendingReferral($business);

        $nonce = $this->staffNonce($token);

        // Burn the nonce with a failing-context POST is not needed:
        // the first POST consumes it even when it succeeds.
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])->assertRedirect();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);

        // Replaying the same nonce against a second referral's
        // screen fails (nonces are bound to one token render).
        [$other, $otherToken] = $this->pendingReferral($business);
        $this->post(route('redeem.confirm', ['token' => $otherToken]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $otherToken]));
        $this->assertSame(Referral::STATE_PENDING, $other->fresh()->state);
    }

    public function test_rerender_invalidates_the_earlier_nonce(): void
    {
        $business = World::business();
        [$referral, $token] = $this->pendingReferral($business);

        $first = $this->staffNonce($token);
        $second = $this->staffNonce($token);
        $this->assertNotSame($first, $second);

        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $first])
            ->assertRedirect();
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);

        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $second])
            ->assertRedirect();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);
    }

    public function test_unknown_and_malformed_tokens_get_the_same_generic_page(): void
    {
        $unknown = $this->get(route('redeem.show', ['token' => str_repeat('c', 43)]));
        $malformed = $this->get(route('redeem.show', ['token' => 'not-a-token']));

        $unknown->assertNotFound()->assertSee('This code is not active');
        $malformed->assertNotFound()->assertSee('This code is not active');
        $this->assertSame(
            $this->genericBody($unknown->getContent()),
            $this->genericBody($malformed->getContent())
        );

        // Rejections are recorded without a business (Section 6.2).
        $this->assertSame(2, ReferralEvent::query()->where('event_type', 'redemption_rejected')->count());
    }

    public function test_failed_resolution_budget_answers_429_with_the_same_body(): void
    {
        config(['referrals.token_resolution_budget_per_ip_hour' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->get(route('redeem.show', ['token' => str_repeat('d', 43)]))->assertNotFound();
        }

        $this->get(route('redeem.show', ['token' => str_repeat('d', 43)]))
            ->assertStatus(429)
            ->assertSee('This code is not active');
    }

    public function test_held_referral_cannot_be_redeemed(): void
    {
        // RDM-22: while held, no transition, no billing, no reward.
        $business = World::business();
        [$referral, $token] = $this->pendingReferral($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee("This one needs the owner's review first", false)
            ->assertDontSee('Mark as Redeemed');

        $service = app(RedemptionService::class);
        $result = $service->redeemReferral($referral, null, '127.0.0.1');
        $this->assertSame('held', $result['outcome']);
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
    }

    public function test_expired_referral_shows_the_expired_state(): void
    {
        $business = World::business();
        [, $token] = $this->pendingReferral($business, ['expires_at' => now()->subHour()]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('This reward expired on');
    }

    public function test_claim_journey_after_vesting(): void
    {
        $business = World::business();
        [$referral, $redeemToken] = $this->pendingReferral($business);

        $nonce = $this->staffNonce($redeemToken);
        $this->post(route('redeem.confirm', ['token' => $redeemToken]), ['nonce' => $nonce])->assertRedirect();

        $reward = Reward::query()->firstOrFail();
        $claimToken = $reward->claim_token_encrypted;

        // Too early: the staff screen shows the date (Section 6.1
        // item 7) and no button.
        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Available on '.$reward->available_at->format('F j, Y'))
            ->assertSee($reward->reward_text_snapshot)
            ->assertDontSee('Mark as Redeemed');

        // After vesting the same link completes the claim.
        $this->travel(15)->days();
        $claimNonce = $this->staffNonce($claimToken);
        $this->post(route('redeem.confirm', ['token' => $claimToken]), ['nonce' => $claimNonce])
            ->assertRedirect(route('redeem.show', ['token' => $claimToken]));

        $reward->refresh();
        $this->assertSame(Reward::STATE_CLAIMED, $reward->state);
        $this->assertNotNull($reward->claimed_at);
        $this->assertDatabaseHas('referral_events', ['event_type' => 'reward_claimed', 'reward_id' => $reward->id]);
    }

    public function test_reward_ready_sms_waits_for_vesting_then_sends_once(): void
    {
        // SMS-02 + EC-23: the text fires when the reward becomes
        // claimable, via the sweep, and the state machine prevents
        // a second send.
        $business = World::business();
        [, $redeemToken] = $this->pendingReferral($business);

        $nonce = $this->staffNonce($redeemToken);
        $this->post(route('redeem.confirm', ['token' => $redeemToken]), ['nonce' => $nonce])->assertRedirect();
        $this->assertCount(0, $this->sms->sent);

        $this->travel(15)->days();
        $this->artisan('rewards:notify-due')->assertSuccessful();

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('+12125550999', $this->sms->sent[0]['to']);
        $this->assertStringContainsString('Congratulations for the successful referral', $this->sms->sent[0]['body']);
        $this->assertStringNotContainsString('succesful', $this->sms->sent[0]['body']);
        $this->assertDatabaseHas('sms_messages', ['template_key' => 'reward_ready', 'delivery_state' => 'sent']);
        $this->assertSame('sent', Reward::query()->firstOrFail()->notification_state);

        $this->artisan('rewards:notify-due')->assertSuccessful();
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_reward_ready_sms_is_suppressed_by_a_platform_opt_out(): void
    {
        $business = World::business();
        [, $redeemToken] = $this->pendingReferral($business);
        \App\Models\SmsOptOut::create(['phone_e164' => '+12125550999', 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $nonce = $this->staffNonce($redeemToken);
        $this->post(route('redeem.confirm', ['token' => $redeemToken]), ['nonce' => $nonce])->assertRedirect();

        $this->travel(15)->days();
        $this->artisan('rewards:notify-due')->assertSuccessful();

        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('suppressed', Reward::query()->firstOrFail()->notification_state);
    }

    public function test_authenticated_owner_of_the_same_business_attributes_the_redemption(): void
    {
        $business = World::business();
        [$referral, $token] = $this->pendingReferral($business);
        $owner = World::owner($business);

        $nonce = $this->staffNonce($token);
        $this->actingAs($owner)
            ->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect();

        $referral->refresh();
        $this->assertSame('attributed', $referral->attribution_state);
        $this->assertSame($owner->id, $referral->redeemed_by_user_id);
        $this->assertSame('owner', ReferralEvent::query()->where('event_type', 'redemption')->first()->actor);
    }

    public function test_authenticated_owner_of_another_business_stays_counter(): void
    {
        // RDM-10: a foreign business's session attributes nothing.
        $business = World::business();
        $other = World::business();
        [, $token] = $this->pendingReferral($business);

        $nonce = $this->staffNonce($token);
        $this->actingAs(World::owner($other))
            ->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect();

        $this->assertSame('counter', ReferralEvent::query()->where('event_type', 'redemption')->first()->actor);
    }

    public function test_vesting_configuration_rejects_out_of_range_values(): void
    {
        // BILL-43: the configured hold must sit inside 7 to 14
        // days; an out-of-range configuration falls back to the
        // 14-day default at mint time, and in-range values apply.
        foreach ([30 => 14, 3 => 14, 7 => 7, 10 => 10] as $configured => $expected) {
            config(['referrals.vesting_days_default' => $configured]);
            $business = World::business();
            [$referral, ] = $this->pendingReferral($business);

            app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

            $reward = Reward::query()->where('referral_id', $referral->id)->firstOrFail();
            $this->assertEqualsWithDelta(
                $expected * 86400,
                abs($reward->available_at->unix() - $referral->fresh()->redeemed_at->unix()),
                120,
                "configured {$configured} should vest in {$expected} days"
            );
        }
    }

    public function test_redemption_time_velocity_recheck_holds_the_second_redemption(): void
    {
        // CONC-01: both referrals were created inside the creation
        // caps (cap counts rewarded referrals), but by redemption
        // time the referrer already has today's rewarded visit, so
        // the second redemption holds instead of paying out.
        $business = World::business(['daily_referral_cap' => 1]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friendA = World::customer('+12125550777', 'A', 'One');
        $friendB = World::customer('+12125550666', 'B', 'Two');
        [$first] = World::referral($membership, $friendA);
        [$second] = World::referral($membership, $friendB);

        $service = app(RedemptionService::class);
        $this->assertSame('redeemed', $service->redeemReferral($first, null, '127.0.0.1')['outcome']);

        $result = $service->redeemReferral($second, null, '127.0.0.1');
        $this->assertSame('held', $result['outcome']);
        $this->assertSame(['velocity_daily'], $second->fresh()->hold_rules);
        $this->assertSame(1, BillingEvent::count());
    }

    public function test_sim_swap_step_up_holds_a_dormant_membership_claim(): void
    {
        // EC-16: the number was linked inside the cooling window
        // and the membership was dormant; Lookup reports a recent
        // SIM change, so the claim holds for review, never blocks
        // outright and never pays.
        $business = World::business();
        $customer = World::customer('+12125550999', 'Reg', 'Ular', [
            'number_linked_at' => now()->subDays(2),
        ]);
        [$membership] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subDays(30)])->save();
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20)]);
        [$reward, $claimToken] = World::reward($membership, $referral);

        $this->lookup->recentSimSwapPhones[] = '+12125550999';

        $nonce = $this->staffNonce($claimToken);
        $this->post(route('redeem.confirm', ['token' => $claimToken]), ['nonce' => $nonce])->assertRedirect();

        $reward->refresh();
        $this->assertTrue($reward->hold);
        $this->assertSame(['number_link_cooling', 'sim_swap_step_up'], $reward->hold_rules);
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->state);
    }

    public function test_claim_inside_the_cooling_window_holds_even_without_risk_signals(): void
    {
        // REF-54: claimability itself is held during the window
        // for a dormant-before-link account; the Lookup signal is
        // not the gate.
        $business = World::business();
        $customer = World::customer('+12125550999', 'Reg', 'Ular', [
            'number_linked_at' => now()->subDays(2),
        ]);
        [$membership] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subDays(30)])->save();
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20)]);
        [$reward, $claimToken] = World::reward($membership, $referral);

        $nonce = $this->staffNonce($claimToken);
        $this->post(route('redeem.confirm', ['token' => $claimToken]), ['nonce' => $nonce])->assertRedirect();

        $reward->refresh();
        $this->assertTrue($reward->hold);
        $this->assertSame(['number_link_cooling'], $reward->hold_rules);
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->state);

        // The queue renders the cooling rule in plain words.
        $this->actingAs(World::owner($business))->get(route('reviews'))
            ->assertOk()
            ->assertSee('cooling window after the customer linked a new number');
    }

    public function test_claim_after_the_cooling_window_proceeds(): void
    {
        $business = World::business();
        $customer = World::customer('+12125550999', 'Reg', 'Ular', [
            'number_linked_at' => now()->subDays(8),
        ]);
        [$membership] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subDays(30)])->save();
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20)]);
        [$reward, $claimToken] = World::reward($membership, $referral);

        $nonce = $this->staffNonce($claimToken);
        $this->post(route('redeem.confirm', ['token' => $claimToken]), ['nonce' => $nonce])->assertRedirect();

        $this->assertSame(Reward::STATE_CLAIMED, $reward->fresh()->state);
    }

    public function test_expiry_sweeper_flips_due_referrals(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        [$due] = World::referral($membership, World::customer('+12125550777', 'A', 'One'), ['expires_at' => now()->subHour()]);
        [$fresh] = World::referral($membership, World::customer('+12125550666', 'B', 'Two'));

        $this->artisan('referrals:expire')->assertSuccessful();

        $this->assertSame(Referral::STATE_EXPIRED, $due->fresh()->state);
        $this->assertSame(Referral::STATE_PENDING, $fresh->fresh()->state);
    }

    private function genericBody(string $html): string
    {
        return preg_replace('/\s+/', ' ', strip_tags($html));
    }
}
