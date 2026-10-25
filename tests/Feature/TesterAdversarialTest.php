<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Plan;
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
 * Adversarial regression suite, added by the independent Tester
 * wave in Sprint 2 (sprint2-test-report.md) and kept permanently
 * by the Sprint 2 fix wave: each test pins a behavior the builder's
 * suite did not assert, at the exact boundary the test matrix
 * names. They run with the suite on every gate, not as one-off
 * probes.
 *
 * ADV-1  RDM-05: a FRESH nonce from token A's live render posted
 *        against token B must not redeem B, and must not burn A's
 *        nonce (the builder pinned only replay of a consumed nonce).
 * ADV-2  RDM-03: a confirm POST with no nonce field at all, at the
 *        HTTP layer (the builder pinned consume(null) at the
 *        service layer only).
 * ADV-3  RDM-21 parallel form: two stale pre-transition copies of
 *        one available reward claimed through the service; exactly
 *        one claim transition may land (the redemption-side stale
 *        race is builder-pinned; the claim side was not).
 * ADV-4  REF-27 boundary at the SHIPPED defaults: daily cap 5.
 *        The 5th redemption of the day succeeds; the 6th is held
 *        with velocity_daily and writes no billing event or reward
 *        (the builder pinned the boundary at a configured cap of 1).
 * ADV-5  REF-28 boundary at the SHIPPED defaults: monthly cap 50,
 *        seeded earlier in the month so the daily cap cannot fire.
 * ADV-6  REF-34 boundary: the 30-minute flow session. A Verify
 *        check at +29 minutes completes; at +31 minutes the flow
 *        is gone and nothing persists.
 * ADV-7  The one uncovered line the builder declared corruption-
 *        only (RedemptionService plan throw): with the plans table
 *        emptied, redemption must fail loud and roll back fully.
 *        (The missing App\Models\Plan import that made this probe
 *        fail was fixed in the Sprint 2 fix wave; the probe now
 *        guards the RuntimeException contract permanently.)
 */
class TesterAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    private function staffNonce(string $token): string
    {
        $response = $this->get(route('redeem.show', ['token' => $token]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'staff screen carries a nonce');

        return $m[1];
    }

    /** @return array{0: Membership, 1: string} */
    private function referrerAt(Business $business, string $phone = '+12125550999'): array
    {
        $referrer = World::customer($phone, 'Reg', 'Ular');

        return World::membership($business, $referrer);
    }

    private function friend(string $phone): Customer
    {
        return World::customer($phone, 'Fri', 'End');
    }

    public function test_adv1_fresh_foreign_nonce_neither_redeems_nor_burns(): void
    {
        $business = World::business();
        [$membership] = $this->referrerAt($business);
        [$refA, $tokenA] = World::referral($membership, $this->friend('+12125550771'));
        [$refB, $tokenB] = World::referral($membership, $this->friend('+12125550772'));

        $nonceA = $this->staffNonce($tokenA);
        $this->staffNonce($tokenB); // B rendered too; its own nonce exists

        // Post B's confirm carrying A's fresh, unconsumed nonce.
        $this->post(route('redeem.confirm', ['token' => $tokenB]), ['nonce' => $nonceA]);

        $this->assertSame(Referral::STATE_PENDING, $refB->fresh()->state, 'foreign nonce must not redeem B');
        $this->assertSame(Referral::STATE_PENDING, $refA->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());

        // A's nonce must still be alive for A: the foreign attempt
        // consumed against B's subject only.
        $this->post(route('redeem.confirm', ['token' => $tokenA]), ['nonce' => $nonceA])
            ->assertRedirect(route('redeem.show', ['token' => $tokenA]));
        $this->assertSame(Referral::STATE_REDEEMED, $refA->fresh()->state);
        $this->assertSame(1, BillingEvent::count());
    }

    public function test_adv2_confirm_without_any_nonce_field_changes_nothing(): void
    {
        $business = World::business();
        [$membership] = $this->referrerAt($business);
        [$referral, $token] = World::referral($membership, $this->friend('+12125550773'));

        $this->staffNonce($token); // a live render exists

        $this->post(route('redeem.confirm', ['token' => $token]), []);

        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
    }

    public function test_adv3_stale_claim_instances_claim_exactly_once(): void
    {
        $business = World::business();
        [$membership] = $this->referrerAt($business);
        [$referral] = World::referral($membership, $this->friend('+12125550774'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [$reward] = World::reward($membership, $referral);
        $stale = Reward::query()->find($reward->id);

        $service = app(RedemptionService::class);
        $first = $service->claimReward($reward, null, '127.0.0.1');
        $second = $service->claimReward($stale, null, '127.0.0.1');

        $this->assertSame('claimed', $first['outcome']);
        $this->assertSame('already', $second['outcome']);
        $claimed = Reward::query()->whereNotNull('claimed_at')->count();
        $this->assertSame(1, $claimed);
        $this->assertSame(Reward::STATE_CLAIMED, $reward->fresh()->state);
    }

    public function test_adv4_daily_velocity_boundary_at_the_default_cap_of_five(): void
    {
        $business = World::business();
        $this->assertSame(5, (int) $business->daily_referral_cap, 'precondition: shipped default cap');
        [$membership] = $this->referrerAt($business);
        $service = app(RedemptionService::class);

        // Four already redeemed today.
        foreach (range(1, 4) as $i) {
            World::referral($membership, $this->friend('+1212555080'.$i), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
            ]);
        }

        // The 5th redemption of the day succeeds and bills.
        [$fifth] = World::referral($membership, $this->friend('+12125550805'));
        $result = $service->redeemReferral($fifth, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertSame(1, BillingEvent::count());
        $this->assertSame(1, Reward::count());

        // The 6th is held at the redemption-time recheck: no
        // transition, no billing event, no reward.
        [$sixth] = World::referral($membership, $this->friend('+12125550806'));
        $result = $service->redeemReferral($sixth, null, '127.0.0.1');
        $this->assertSame('held', $result['outcome']);
        $sixth->refresh();
        $this->assertSame(Referral::STATE_PENDING, $sixth->state);
        $this->assertTrue((bool) $sixth->hold);
        $this->assertSame(['velocity_daily'], $sixth->hold_rules);
        $this->assertSame(1, BillingEvent::count());
        $this->assertSame(1, Reward::count());
    }

    public function test_adv5_monthly_velocity_boundary_at_the_default_cap_of_fifty(): void
    {
        $business = World::business();
        $this->assertSame(50, (int) $business->monthly_referral_cap, 'precondition: shipped default cap');
        [$membership] = $this->referrerAt($business);
        $service = app(RedemptionService::class);

        // Fifty redeemed earlier this month, at mid-month noon
        // UTC (morning of the same calendar day in the business
        // timezone): never today and never last month in either
        // timezone, so only the monthly rule can fire.
        $day = now()->day === 15 ? 16 : 15;
        $earlierThisMonth = now()->startOfMonth()->day($day)->setTime(12, 0);
        foreach (range(1, 50) as $i) {
            World::referral($membership, $this->friend('+12125551'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => $earlierThisMonth,
            ]);
        }

        [$fiftyFirst] = World::referral($membership, $this->friend('+12125551999'));
        $result = $service->redeemReferral($fiftyFirst, null, '127.0.0.1');

        $this->assertSame('held', $result['outcome']);
        $fiftyFirst->refresh();
        $this->assertSame(Referral::STATE_PENDING, $fiftyFirst->state);
        if (now()->day === 1) {
            // On the 1st, "earlier this month" is also "today", so
            // either velocity rule is a correct trip.
            $this->assertContains($fiftyFirst->hold_rules[0], ['velocity_daily', 'velocity_monthly']);
        } else {
            $this->assertSame(['velocity_monthly'], $fiftyFirst->hold_rules);
        }
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
    }

    public function test_adv6_flow_session_boundary_twenty_nine_and_thirty_one_minutes(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        // Inside the window: enroll, wait 29 minutes, verify.
        $this->travelTo(now()->startOfMinute());
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->travel(29)->minutes();
        $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share'));
        $this->assertSame(1, Membership::count());
        $this->travelBack();

        // Past the window: a fresh flow, enroll, wait 31 minutes.
        $business2 = World::business();
        [, $qrToken2] = World::qr($business2);
        $this->travelTo(now()->startOfMinute());
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken2]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken2]), [
            'first_name' => 'Kim', 'last_name' => 'Lee', 'phone' => '2125550222',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->travel(31)->minutes();
        $response = $this->post(route('refer.verify.check'), ['code' => '123456']);
        $response->assertRedirect(route('home'));
        // Nothing persisted for the expired flow's phone: the only
        // customer and membership in the DB are the first flow's.
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Membership::count());
        $this->travelBack();
    }

    public function test_adv7_redemption_with_no_plan_configured_fails_loud_and_rolls_back(): void
    {
        $business = World::business();
        [$membership] = $this->referrerAt($business);
        [$referral] = World::referral($membership, $this->friend('+12125550775'));

        // Corrupt the world: no plan rows at all (the uncovered
        // RedemptionService throw the build report declares).
        $business->forceFill(['current_plan_id' => null])->save();
        Plan::query()->delete();

        $thrown = null;
        try {
            app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        // Regression guard for the Sprint 2 Tester finding G-1:
        // RedemptionService called Plan::launch() with no
        // App\Models\Plan import, so the fallback fataled with an
        // Error and the designed RuntimeException was unreachable
        // in any no-plan state. The import landed in the fix wave;
        // this probe now proves the exception contract holds.
        $this->assertNotNull($thrown, 'redemption without any plan must fail loud, never bill silently');
        $this->assertStringContainsString('has no plan configured', $thrown->getMessage());
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
    }
}
