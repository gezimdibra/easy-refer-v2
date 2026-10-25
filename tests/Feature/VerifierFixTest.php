<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Security\RateBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Regression tests for the Sprint 2 verifier fix wave: the
 * shared failed-resolution budget and its review-queue trail,
 * the held receipt states, the six-box code assembly, the
 * form-field device signal, and AP-08's single Primary.
 */
class VerifierFixTest extends TestCase
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

    private function carry($response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    public function test_staff_voided_presentation_feeds_the_owner_queue(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend, ['state' => Referral::STATE_VOIDED]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertNotFound()
            ->assertSee('This code is not active');

        $this->assertDatabaseHas('referral_events', [
            'event_type' => 'redemption_rejected',
            'business_id' => $business->id,
            'referral_id' => Referral::query()->first()->id,
        ]);

        $this->actingAs($owner)->get(route('reviews'))
            ->assertOk()
            ->assertSee('Rejected presentations')
            ->assertSee('A code that could not be used was presented');
    }

    public function test_flow_resolution_budget_answers_429_with_the_same_body(): void
    {
        $budget = app(RateBudgetService::class);
        $key = RateBudgetService::keyFor('token_fail', hash('sha256', '127.0.0.1'));
        for ($i = 0; $i < 100; $i++) {
            $budget->hit($key, 3600);
        }

        // The 101st failed resolution on the customer flow: same
        // generic page, status 429 (Sections 3 and 10.4).
        $this->get(route('refer.landing', ['token' => str_repeat('q', 43)]))
            ->assertStatus(429)
            ->assertSee('This code is not active');

        $this->assertDatabaseHas('referral_events', [
            'event_type' => 'redemption_rejected',
            'business_id' => null,
        ]);
    }

    public function test_held_receipt_pages_show_the_held_state_without_credentials(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');

        [, $redeemToken] = World::referral($membership, $friend, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        $this->get(route('refer.redeem.page', ['token' => $redeemToken]))
            ->assertOk()
            ->assertSee('Held for review')
            ->assertDontSee('Show this at')
            ->assertDontSee(route('refer.redeem.qr', ['token' => $redeemToken]));

        [$redeemed] = World::referral($membership, World::customer('+12125550666', 'Bee', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [, $claimToken] = World::reward($membership, $redeemed, [
            'hold' => true, 'hold_rules' => ['redemption_burst'], 'held_at' => now(),
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Held for review')
            ->assertDontSee('will be ready on');
    }

    public function test_code_boxes_assemble_without_javascript(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        // The six boxes posted individually (the no-JS path) are
        // assembled into the code the provider checks.
        $this->post(route('refer.verify.check'), [
            'code_1' => '1', 'code_2' => '2', 'code_3' => '3',
            'code_4' => '4', 'code_5' => '5', 'code_6' => '6',
        ])->assertRedirect(route('refer.share'));
    }

    public function test_device_signal_arrives_through_the_form_field(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Ay', 'last_name' => 'One', 'phone' => '2125550111',
            'device_signal' => 'device-abc',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456', 'device_signal' => 'device-abc'])->assertRedirect(route('refer.share'));
        $membershipA = Membership::query()->firstOrFail();

        $this->app->forgetInstance('session');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        $this->carry($this->post(route('refer.friend.claim', ['token' => $membershipA->plainShareToken()]), [
            'first_name' => 'Bee', 'last_name' => 'Two', 'phone' => '2125550222',
            'device_signal' => 'device-abc',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456', 'device_signal' => 'device-abc'])->assertRedirect();

        $referral = Referral::query()->firstOrFail();
        $this->assertTrue($referral->hold);
        $this->assertContains('device_signal_reuse', $referral->hold_rules);
    }

    public function test_review_queue_lists_pending_number_links(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $customer = World::customer('+12125550999', 'Reg', 'Ular', [
            'number_linked_at' => now()->subDay(),
        ]);
        World::membership($business, $customer);

        ReferralEvent::record('number_linked', 'customer', $business, null, null, null, [
            'customer_id' => $customer->id,
        ], '127.0.0.1');

        $this->actingAs($owner)->get(route('reviews'))
            ->assertOk()
            ->assertSee('Pending number links')
            ->assertSee('Reg linked a new number');
    }

    public function test_home_and_vertical_primaries_follow_the_amended_ap08(): void
    {
        // AP-08 as amended 2026-10-23 (coordinator ruling on CF-04):
        // exactly one Primary per VIEWPORT region, not per page.
        // The original pin (exactly two Primaries in main: hero and
        // pricing card) predates the benchmark fix lane, whose
        // rubric (landing-benchmark.md, C-03 / M-02) requires the
        // primary action repeated after every major proof block so
        // no scroll position sits more than one viewport from a
        // visible Primary, plus the mobile sticky bar, which its
        // observer shows only while no other Primary is in view.
        // Reconciled at the release 07 integration (2026-10-24):
        // the per-viewport rule stands, and what this test pins is
        // its anti-creep core. Every Primary in main is the Start
        // free register action (no other action wears Primary;
        // header and starter-kit buttons stay Secondary), and the
        // totals are exact, so adding or removing a repeat fails
        // loudly: home carries the hero, six section repeats, the
        // pricing card, the calculator, and the sticky bar (10);
        // the vertical carries the hero, the pricing card, and the
        // calculator (3). Flagged for ratification in spec
        // amendment 8 (docs-side integration).
        $expected = [
            route('home') => 10,
            route('vertical', ['vertical' => 'barbers']) => 3,
        ];
        foreach ($expected as $url => $count) {
            $html = $this->get($url)->assertOk()->getContent();
            $main = substr($html, strpos($html, '<main'), strpos($html, '</main>') - strpos($html, '<main'));
            $this->assertSame($count, substr_count($main, 'btn btn-primary'), $url);
            // One further Start free wears the accent rank: the
            // closing band's, on its ink ground.
            $this->assertSame($count + 1, substr_count($main, '>Start free</a>'), $url);
            $this->assertSame(1, substr_count($main, 'btn btn-accent'), $url);
        }
    }

    public function test_staff_expired_presentations_are_recorded(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');

        [, $expiredToken] = World::referral($membership, $friend, ['expires_at' => now()->subHour()]);
        $this->get(route('redeem.show', ['token' => $expiredToken]))
            ->assertOk()
            ->assertSee('This reward expired on');
        $this->assertDatabaseHas('referral_events', [
            'event_type' => 'redemption_rejected', 'business_id' => $business->id,
        ]);

        [$redeemed] = World::referral($membership, World::customer('+12125550666', 'Bee', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [, $claimToken] = World::reward($membership, $redeemed, ['state' => Reward::STATE_EXPIRED]);
        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertNotFound()
            ->assertSee('This code is not active');
    }

    public function test_terminal_presentation_past_the_budget_answers_429(): void
    {
        $budget = app(RateBudgetService::class);
        $key = RateBudgetService::keyFor('token_fail', hash('sha256', '127.0.0.1'));
        for ($i = 0; $i < 100; $i++) {
            $budget->hit($key, 3600);
        }

        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        [, $token] = World::referral($membership, World::customer('+12125550777', 'Fri', 'End'), [
            'state' => Referral::STATE_VOIDED,
        ]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertStatus(429)
            ->assertSee('This code is not active');
    }

    public function test_expired_presentation_past_the_budget_answers_429(): void
    {
        $budget = app(RateBudgetService::class);
        $key = RateBudgetService::keyFor('token_fail', hash('sha256', '127.0.0.1'));
        for ($i = 0; $i < 100; $i++) {
            $budget->hit($key, 3600);
        }

        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        [, $token] = World::referral($membership, World::customer('+12125550777', 'Fri', 'End'), [
            'expires_at' => now()->subHour(),
        ]);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertStatus(429)
            ->assertSee('This code is not active');
    }

    public function test_share_message_falls_back_when_no_friend_reward_text(): void
    {
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('invited you to '.$business->name);
    }

    public function test_attribution_and_consent_cookies_carry_real_lifetimes(): void
    {
        // Regression for the Symfony Cookie::create semantics:
        // the third argument is an expiry timestamp, not minutes.
        // A tagged landing sets er_attrib for ~90 days, and the
        // consent choice persists for ~a year; a Max-Age at or
        // near zero means a real browser discards both.
        $response = $this->get('/faq?utm_source=google');
        $attrib = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'er_attrib');
        $this->assertNotNull($attrib);
        $this->assertGreaterThan(89 * 86400, $attrib->getExpiresTime() - time());

        $response = $this->post(route('consent'), ['choice' => 'accepted']);
        $consent = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'er_consent');
        $this->assertNotNull($consent);
        $this->assertGreaterThan(364 * 86400, $consent->getExpiresTime() - time());
    }

    public function test_plan_seed_keeps_the_free_credit_on_the_business_row(): void
    {
        $plan = \App\Models\Plan::launch();
        $this->assertNull($plan->included_referrals_per_period);

        $business = World::business();
        $this->assertSame(5, $business->free_visits_remaining);
    }

    public function test_friend_claim_post_records_failed_resolutions_and_obeys_the_budget(): void
    {
        // Re-check M-9 remainder: the claim POST's unknown-token
        // branch went through the same guard as its GET twin.
        $key = RateBudgetService::keyFor('token_fail', hash('sha256', '127.0.0.1'));
        $before = (int) (\App\Models\RateBudget::query()->where('key', $key)->value('count') ?? 0);

        $this->post(route('refer.friend.claim', ['token' => str_repeat('u', 43)]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertNotFound();

        $after = (int) \App\Models\RateBudget::query()->where('key', $key)->value('count');
        $this->assertSame($before + 1, $after, 'the POST failure hit the shared budget');

        $budget = app(RateBudgetService::class);
        for ($i = 0; $i < 100; $i++) {
            $budget->hit($key, 3600);
        }

        $this->post(route('refer.friend.claim', ['token' => str_repeat('u', 43)]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertStatus(429);
    }

    public function test_friend_form_device_field_is_a_real_hidden_input(): void
    {
        // Re-check NEW-1: the device-signal input shipped with
        // backslash-escaped quotes, rendering as a visible stray
        // field under a mangled name.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $page = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk();
        $page->assertSee('<input type="hidden" name="device_signal" data-device-signal>', false);
        $page->assertDontSee('\\"device_signal\\"', false);
    }

    public function test_share_button_survives_apostrophes_in_names(): void
    {
        // Re-check NEW-3: the native-share payload is JSON-encoded
        // (Js::from), so an apostrophe in the business name cannot
        // terminate the JS string and kill the button.
        $business = World::business(['name' => "Joe's Diner"]);
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));

        $page = $this->get(route('refer.share'))->assertOk();
        $page->assertSee('navigator.share({', false);
        $page->assertSee('Joe\\u0027s Diner', false);
    }
}
