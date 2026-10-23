<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The /refer flow's edge branches (spec Section 3): resend
 * cadence, wrong-number escape, QR image endpoints, the QR
 * revocation grace, provider failure mapping, and the EC-16 and
 * EC-17 identity paths.
 */
class FlowBranchTest extends TestCase
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

    private function beginEnrollment(Business $business, string $qrToken, string $phone = '2125550111'): void
    {
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => $phone,
        ])->assertRedirect(route('refer.verify')));
    }

    public function test_resend_honors_the_cooldown_then_sends_a_fresh_code(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);
        $this->assertCount(1, $this->verify->starts);

        // Inside the 60-second cooldown: a notice, no provider call.
        $this->post(route('refer.verify.resend'))->assertRedirect(route('refer.verify'));
        $this->get(route('refer.verify'))->assertSee('We sent you a new code');
        $this->assertCount(1, $this->verify->starts);

        $this->travel(61)->seconds();
        $this->post(route('refer.verify.resend'))->assertRedirect(route('refer.verify'));
        $this->assertCount(2, $this->verify->starts);

        // The fresh code starts a fresh check budget.
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));
    }

    public function test_resend_stops_at_the_flow_session_start_budget(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);

        // Raise the provider-side budgets so the flow-session
        // budget is the only one that can answer here.
        config(['referrals.verify.starts_per_phone_per_hour' => 50]);
        config(['referrals.verify.starts_per_ip_per_hour' => 50]);
        config(['referrals.verify.starts_per_business_per_hour' => 500]);
        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            $this->post(route('refer.verify.resend'))->assertRedirect(route('refer.verify'));
        }

        $this->travel(61)->seconds();
        $this->post(route('refer.verify.resend'))
            ->assertOk()
            ->assertSee('Too many tries');
    }

    public function test_wrong_number_escape_returns_to_the_form_and_clears_the_pending(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);

        $this->post(route('refer.verify.change'))
            ->assertRedirect(route('refer.landing', ['token' => $qrToken]));

        // No pending remains: the code screen bounces home.
        $this->get(route('refer.verify'))->assertRedirect(route('home'));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('home'));
        $this->post(route('refer.verify.resend'))->assertRedirect(route('home'));
        $this->post(route('refer.verify.change'))->assertRedirect(route('home'));
    }

    public function test_qr_image_endpoints_serve_svg(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));

        $this->get(route('refer.share.qr'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $referrer = Membership::query()->firstOrFail();
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $redeemToken] = World::referral($referrer, $friend);
        [, $claimToken] = World::reward($referrer, World::referral($referrer, World::customer('+12125550666', 'Bee', 'Two'))[0]);

        $this->get(route('refer.redeem.qr', ['token' => $redeemToken]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
        $this->get(route('refer.claim.qr', ['token' => $claimToken]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_revoked_qr_grace_lets_an_in_flight_flow_finish_then_closes(): void
    {
        $business = World::business();
        [$qr, $qrToken] = World::qr($business);

        // The customer is mid-flow when the owner revokes the code.
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $qr->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();

        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertRedirect(route('refer.verify')));

        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));

        // A fresh arrival after revocation gets the generic page,
        // and an in-flight flow past the 15-minute grace does too.
        $this->flushCookies();
        $this->get(route('refer.landing', ['token' => $qrToken]))->assertSee('This code is not active');
    }

    public function test_provider_error_at_start_shows_the_designed_error_page(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->verify->startOutcomes['+12125550111'] = 'provider_error';

        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertOk()->assertSee('Something went wrong on our end');
    }

    public function test_provider_error_at_check_returns_to_the_code_screen(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);

        $this->verify->checkOutcome = 'provider_error';
        $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.verify'));
        $this->get(route('refer.verify'))->assertSee('Something went wrong on our end');
    }

    public function test_landline_blocked_on_both_channels_shows_the_phone_error(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->verify->landlinePhone = '+12125550111';
        $this->verify->landlineBlocksCall = true;

        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertSessionHasErrors('phone');
    }

    public function test_turnstile_failure_blocks_enrollment_and_claim(): void
    {
        Config::set('services.turnstile.site_key', 'site');
        Config::set('services.turnstile.secret_key', 'secret');
        Http::fake(['*' => Http::response(['success' => false], 200)]);

        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertSessionHasErrors('turnstile');
        $this->assertCount(0, $this->verify->starts);

        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);
        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212',
        ])->assertSessionHasErrors('turnstile');
    }

    public function test_suspended_business_flow_is_not_active(): void
    {
        $business = World::business(['status' => 'suspended']);
        [, $qrToken] = World::qr($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertNotFound()->assertSee('This code is not active');

        $this->get(route('refer.friend', ['token' => $shareToken]))
            ->assertNotFound()->assertSee('This code is not active');

        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212',
        ])->assertNotFound()->assertSee('This code is not active');
    }

    public function test_enrollment_with_a_revoked_code_and_no_flow_is_generic(): void
    {
        $business = World::business();
        [$qr, $qrToken] = World::qr($business);
        $qr->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();

        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertNotFound()->assertSee('This code is not active');
    }

    public function test_shared_phone_name_update_keeps_one_identity(): void
    {
        // EC-17: a family phone verified under a new name updates
        // the shared record; no second customer appears.
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->beginEnrollment($business, $qrToken);
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));

        $this->flushCookies();
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Patricia', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));

        $this->assertSame(1, Customer::count());
        $this->assertSame('Patricia', Customer::query()->first()->first_name);
    }

    public function test_number_linking_across_two_verifications_in_one_flow(): void
    {
        // EC-16: verified as one number, then a claim in the same
        // flow verifies a second number: the numbers link on the
        // original identity and the cooling window starts.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));
        $original = Customer::query()->firstOrFail();

        $referrer = World::customer('+12125550888', 'Ola', 'Nord');
        [, $shareToken] = World::membership($business, $referrer);

        $this->carry($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'New', 'last_name' => 'Number', 'phone' => '2125550333',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $this->assertNotNull($original->fresh()->number_linked_at);
        $this->assertDatabaseHas('customer_number_history', [
            'customer_id' => $original->id,
            'phone_e164' => '+12125550333',
        ]);
        $this->assertDatabaseHas('referral_events', ['event_type' => 'number_linked', 'business_id' => $business->id]);
    }

    public function test_self_referral_by_email_earns_nothing(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular', [
            'email' => 'reg@gmail.com',
            'canonical_email' => \App\Support\CanonicalEmail::of('reg@gmail.com'),
        ]);
        [, $shareToken] = World::membership($business, $referrer);

        // Same person, different phone, plus-tag variant of the
        // same canonical address (the Section 4.1 canonical rule:
        // case-insensitive, plus tags ignored).
        $this->carry($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Reg', 'last_name' => 'Ular', 'phone' => '2125550444',
            'email' => 'Reg+friend@gmail.com',
        ])->assertRedirect(route('refer.verify')));

        $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertOk()
            ->assertSee('You cannot refer yourself');

        $this->assertSame(0, Referral::count());
    }

    private function flushCookies(): void
    {
        $this->app->forgetInstance('session');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }
}
