<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Membership;
use App\Models\SmsMessage;
use App\Services\Referral\EnrollmentService;
use App\Support\SmsSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The coverage tail: guard branches that need surgical setups
 * (spec Sections 3 and 7.1). Concurrency-only recovery catches
 * are declared in the Sprint 2 report instead of being forced.
 */
class CoverageTailTest extends TestCase
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

    public function test_verify_screens_with_a_flow_but_no_pending_go_home(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));

        $this->get(route('refer.verify'))->assertRedirect(route('home'));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('home'));
        $this->post(route('refer.verify.resend'))->assertRedirect(route('home'));
        $this->post(route('refer.verify.change'))->assertRedirect(route('home'));
    }

    public function test_share_page_after_the_membership_vanishes_goes_home(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));

        Membership::query()->delete();

        $this->get(route('refer.share'))->assertRedirect(route('home'));
    }

    public function test_share_qr_guards(): void
    {
        $this->get(route('refer.share.qr'))->assertRedirect(route('home'));

        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));

        Membership::query()->delete();

        $this->get(route('refer.share.qr'))->assertRedirect(route('home'));
    }

    public function test_malformed_and_unknown_share_and_qr_tokens(): void
    {
        $this->get(route('refer.friend', ['token' => 'short']))
            ->assertNotFound()
            ->assertSee('Something is not right with that link');

        $this->post(route('refer.friend.claim', ['token' => str_repeat('w', 43)]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212',
        ])->assertNotFound()->assertSee('Something is not right with that link');

        $this->post(route('refer.enroll', ['token' => str_repeat('z', 43)]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertNotFound()->assertSee('This code is not active');
    }

    public function test_flow_session_start_budget_blocks_a_second_start(): void
    {
        config(['referrals.verify.starts_per_flow_session' => 1]);

        $business = World::business();
        [, $qrToken] = World::qr($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertRedirect(route('refer.verify')));
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));

        // The same flow tries to start a second verification (a
        // friend claim): the flow budget answers first.
        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212',
        ])->assertOk()->assertSee('Too many tries');
    }

    public function test_sms_message_customer_relation_and_gsm_guard(): void
    {
        $business = World::business();
        $customer = World::customer('+12125550999', 'Reg', 'Ular');
        $message = SmsMessage::create([
            'business_id' => $business->id, 'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164, 'purpose' => 'reward_ready',
            'rendered_body' => 'hi', 'delivery_state' => 'sent', 'template_key' => 'reward_ready',
            'consent_basis' => 'enrollment', 'opt_out_state_at_send' => false,
        ]);
        $this->assertSame($customer->id, $message->customer->id);

        // The GSM guard's multi-unit input branch, exercised
        // directly: mb_str_split can never yield it, so the guard
        // is verified at the unit boundary it defends.
        $method = new \ReflectionMethod(SmsSegments::class, 'isGsmBasic');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke(null, 'ab'));
        $this->assertTrue($method->invoke(null, 'a'));
    }
}
