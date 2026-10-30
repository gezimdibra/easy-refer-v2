<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * U2 (launch-readiness persona F-2): the flow and staff state
 * pages rendered their dates in UTC, so an evening instant read
 * one calendar day late for the business. Every user-facing date
 * on those surfaces now renders through Business::localTime,
 * exactly as the receipt flood already did (R4-U4 pattern).
 * Every fixture below picks an instant whose UTC calendar date
 * differs from its America/New_York calendar date, so a UTC
 * render fails the pin and a local render passes it. Stored
 * values, expiry logic, and comparisons are untouched: this is
 * rendering only.
 */
class FlowStaffLocalTimeTest extends TestCase
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

    public function test_redeem_page_good_until_renders_the_business_local_date(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        // 2026-11-26 01:00 UTC is 2026-11-25 20:00 in New York.
        [, $token] = World::referral($membership, $friend, [
            'expires_at' => Carbon::parse('2026-11-26 01:00:00', 'UTC'),
        ]);

        $this->get(route('refer.redeem.page', ['token' => $token]))
            ->assertOk()
            ->assertSee('Good until November 25, 2026')
            ->assertDontSee('November 26, 2026');
    }

    public function test_redeem_page_expired_renders_the_business_local_date(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend, [
            'state' => Referral::STATE_EXPIRED,
            'expires_at' => Carbon::parse('2026-11-26 01:00:00', 'UTC'),
        ]);

        $this->get(route('refer.redeem.page', ['token' => $token]))
            ->assertOk()
            ->assertSee('This reward expired on November 25, 2026')
            ->assertDontSee('November 26, 2026');
    }

    public function test_claim_page_availability_renders_the_business_local_date_and_weekday(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        [, $qrToken] = World::qr($business);

        // Enroll the referrer so the claim page runs inside their
        // own verified flow session (the only place the date shows).
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));
        $membership = Membership::query()->firstOrFail();

        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        // 2026-11-06 00:04 UTC is Thursday 2026-11-05 19:04 in
        // New York; the UTC reading is Friday, November 6.
        [, $claimToken] = World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => Carbon::parse('2026-11-06 00:04:00', 'UTC'),
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Thursday, November 5, 2026')
            ->assertDontSee('Friday, November 6, 2026')
            ->assertDontSee('November 6, 2026');
    }

    public function test_staff_already_redeemed_renders_the_business_local_date(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        // 2026-10-26 02:30 UTC is 2026-10-25 22:30 in New York.
        [, $token] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => Carbon::parse('2026-10-26 02:30:00', 'UTC'),
        ]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee("Fri's reward was redeemed on October 25, 2026", false)
            ->assertDontSee('October 26, 2026');
    }

    public function test_staff_already_claimed_renders_the_business_local_date(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [, $claimToken] = World::reward($membership, $referral, [
            'state' => Reward::STATE_CLAIMED,
            'claimed_at' => Carbon::parse('2026-10-26 02:30:00', 'UTC'),
        ]);

        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee("Reg's reward was claimed on October 25, 2026", false)
            ->assertDontSee('October 26, 2026');
    }

    public function test_staff_expired_renders_the_business_local_date(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend, [
            'state' => Referral::STATE_EXPIRED,
            'expires_at' => Carbon::parse('2026-11-26 01:00:00', 'UTC'),
        ]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('This reward expired on November 25, 2026')
            ->assertDontSee('November 26, 2026');
    }

    public function test_staff_not_yet_renders_the_business_local_date(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [, $claimToken] = World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => Carbon::parse('2026-11-06 00:04:00', 'UTC'),
            'notification_state' => 'queued',
        ]);

        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Available on November 5, 2026')
            ->assertDontSee('November 6, 2026');
    }
}
