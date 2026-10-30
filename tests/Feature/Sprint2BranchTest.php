<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Jobs\SendFriendLinkSms;
use App\Jobs\SendRewardReadySms;
use App\Models\FlowSession;
use App\Models\Lead;
use App\Models\Post;
use App\Models\RateBudget;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsOptOut;
use App\Services\Flow\FlowSessionManager;
use App\Services\Referral\RedemptionService;
use App\Services\Referral\VerificationService;
use App\Services\Security\RateBudgetService;
use App\Services\Security\TurnstileVerifier;
use App\Services\Twilio\NullPhoneLookupGateway;
use App\Services\Twilio\NullPhoneVerificationGateway;
use App\Services\Twilio\NullSmsGateway;
use App\Services\Twilio\TwilioPhoneLookupGateway;
use App\Support\CanonicalEmail;
use App\Support\HoldReasons;
use App\Support\QrImage;
use App\Support\SmsSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeTwilioHttpClient;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Branch coverage for the Sprint 2 surface that the journey tests
 * do not reach: provider configuration states, error and edge
 * branches of the flow controllers, the lookup gateway, the null
 * gateways (the staging posture), and small support units. Every
 * test names the behavior it pins; none exists only to color a
 * line.
 */
class Sprint2BranchTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->sms = new FakeSmsGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
    }

    // ------------------------------------------------------------------
    // Provider binding discipline (AppServiceProvider)

    public function test_container_resolves_null_gateways_when_twilio_is_unconfigured(): void
    {
        $app = $this->freshAppWithoutFakes();

        $this->assertInstanceOf(NullPhoneVerificationGateway::class, $app->make(PhoneVerificationGateway::class));
        $this->assertInstanceOf(NullSmsGateway::class, $app->make(SmsGateway::class));
        $this->assertInstanceOf(NullPhoneLookupGateway::class, $app->make(PhoneLookupGateway::class));
    }

    public function test_container_resolves_real_gateways_when_twilio_is_configured(): void
    {
        $this->refreshApplication();
        Config::set('services.twilio.account_sid', 'AC_TEST');
        Config::set('services.twilio.api_key_sid', 'SK_TEST');
        Config::set('services.twilio.api_key_secret', 'secret');
        Config::set('services.twilio.verify_service_sid', 'VA_TEST');
        Config::set('services.twilio.messaging_service_sid', 'MG_TEST');

        $app = $this->app;

        $this->assertInstanceOf(\App\Services\Twilio\TwilioPhoneVerificationGateway::class, $app->make(PhoneVerificationGateway::class));
        $this->assertInstanceOf(\App\Services\Twilio\TwilioSmsGateway::class, $app->make(SmsGateway::class));
        $this->assertInstanceOf(TwilioPhoneLookupGateway::class, $app->make(PhoneLookupGateway::class));
    }

    public function test_null_gateways_fail_closed_and_neutral(): void
    {
        $verify = new NullPhoneVerificationGateway;
        $this->assertSame('provider_error', $verify->start('+12125550111')['outcome']);
        $this->assertSame('denied', $verify->check('+12125550111', '123456')['outcome']);

        $sms = new NullSmsGateway;
        $this->assertNull($sms->send('+12125550111', 'x'));
        $this->assertSame(SmsSegments::count('x'), $sms->segmentCount('x'));

        $lookup = new NullPhoneLookupGateway;
        $this->assertFalse($lookup->lookup('+12125550111')['sim_changed_recently']);
    }

    private function freshAppWithoutFakes(): \Illuminate\Contracts\Foundation\Application
    {
        $this->refreshApplication();

        return $this->app;
    }

    // ------------------------------------------------------------------
    // Lookup gateway over the transport seam

    public function test_lookup_gateway_maps_sim_swap_and_line_type(): void
    {
        Config::set('services.twilio.account_sid', 'AC_TEST');
        Config::set('services.twilio.api_key_sid', 'SK_TEST');
        Config::set('services.twilio.api_key_secret', 'secret');

        $http = new FakeTwilioHttpClient;
        $http->respondWhen('PhoneNumbers', 200, [
            'sim_swap' => ['last_sim_swap' => ['date' => now()->subDays(3)->toIso8601String()]],
            'line_type_intelligence' => ['line_type' => 'mobile', 'carrier_name' => 'Test Carrier'],
        ]);

        $gateway = new TwilioPhoneLookupGateway($http);
        $result = $gateway->lookup('+12125550111');

        $this->assertTrue($result['sim_changed_recently']);
        $this->assertSame('mobile', $result['line_type']);
        $this->assertSame('Test Carrier', $result['carrier']);
    }

    public function test_lookup_gateway_is_neutral_on_provider_failure(): void
    {
        Config::set('services.twilio.account_sid', 'AC_TEST');
        Config::set('services.twilio.api_key_sid', 'SK_TEST');
        Config::set('services.twilio.api_key_secret', 'secret');

        $gateway = new TwilioPhoneLookupGateway(new FakeTwilioHttpClient);
        $result = $gateway->lookup('+12125550111');

        $this->assertFalse($result['sim_changed_recently']);
        $this->assertNull($result['line_type']);
    }

    // ------------------------------------------------------------------
    // Turnstile verifier branches

    public function test_turnstile_passes_unconfigured_and_verifies_when_configured(): void
    {
        $verifier = new TurnstileVerifier;
        $this->assertTrue($verifier->passes(null, '127.0.0.1'));

        Config::set('services.turnstile.site_key', 'site');
        Config::set('services.turnstile.secret_key', 'secret');

        $this->assertFalse($verifier->passes(null, '127.0.0.1'));
        $this->assertFalse($verifier->passes('', '127.0.0.1'));

        $answer = true;
        Http::fake(function () use (&$answer) {
            return Http::response(['success' => $answer], 200);
        });
        $this->assertTrue($verifier->passes('token', '127.0.0.1'));

        $answer = false;
        $this->assertFalse($verifier->passes('token', '127.0.0.1'));

        Http::fake(['*' => Http::response(null, 500)]);
        $this->assertFalse($verifier->passes('token', '127.0.0.1'));
    }

    public function test_turnstile_failure_blocks_the_lead_forms(): void
    {
        Config::set('services.turnstile.site_key', 'site');
        Config::set('services.turnstile.secret_key', 'secret');
        Http::fake(['*' => Http::response(['success' => false], 200)]);
        Mail::fake();

        $this->from(route('home'))
            ->post(route('newsletter'), ['email' => 'lead@example.test', 'cf-turnstile-response' => 'bad'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Lead::count());
    }

    public function test_lead_budget_blocks_past_ten_per_ip(): void
    {
        Mail::fake();

        // The route throttle and the controller's database budget
        // are two layers; pre-seeding the database budget exercises
        // the guard's own refusal on the next request.
        $budgets = app(RateBudgetService::class);
        $key = RateBudgetService::keyFor('lead_newsletter', hash('sha256', '127.0.0.1'));
        for ($i = 0; $i < 10; $i++) {
            $budgets->hit($key);
        }

        $this->from(route('home'))
            ->post(route('newsletter'), ['email' => 'one-too-many@example.test'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Lead::count());
    }

    // ------------------------------------------------------------------
    // Budgets and flow sessions

    public function test_rate_budget_windows_reset_and_count_reads(): void
    {
        $budgets = app(RateBudgetService::class);
        $key = RateBudgetService::keyFor('verify_phone', 'abc');

        $this->assertSame(1, $budgets->hit($key));
        $this->assertSame(2, $budgets->hit($key));
        $this->assertSame(2, $budgets->count($key));
        $this->assertSame(0, $budgets->count(RateBudgetService::keyFor('verify_phone', 'other')));

        // Age the window past its span: the count restarts.
        RateBudget::query()->where('key', $key)->update(['window_started_at' => now()->subHours(2)]);
        $this->assertSame(0, $budgets->count($key));
        $this->assertSame(1, $budgets->hit($key));
    }

    public function test_verification_service_reports_ip_and_business_budgets(): void
    {
        $business = World::business();
        $budgets = app(RateBudgetService::class);
        $service = app(VerificationService::class);

        $ipKey = RateBudgetService::keyFor('verify_ip', hash('sha256', '10.0.0.1'));
        for ($i = 0; $i < 10; $i++) {
            $budgets->hit($ipKey);
        }
        $this->assertSame('ip', $service->budgetState('+12125550111', '10.0.0.1', $business));

        $businessKey = RateBudgetService::keyFor('verify_business', (string) $business->id);
        for ($i = 0; $i < 50; $i++) {
            $budgets->hit($businessKey);
        }
        $this->assertSame('business', $service->budgetState('+12125550111', '10.0.0.2', $business));
        $this->assertSame('rate_limited', $service->start('+12125550111', '10.0.0.2', $business)['outcome']);
    }

    public function test_flow_session_manager_lifecycle(): void
    {
        $manager = app(FlowSessionManager::class);

        [$record, $token] = $manager->start(['k' => 'v']);
        $this->assertSame('v', $manager->get($record, 'k'));
        $this->assertSame('fallback', $manager->get($record, 'missing', 'fallback'));

        $request = \Illuminate\Http\Request::create('/', 'GET', [], [$manager->cookieName() => $token]);
        $this->assertSame($record->id, $manager->fromRequest($request)?->id);

        $unknown = \Illuminate\Http\Request::create('/', 'GET', [], [$manager->cookieName() => 'nope']);
        $this->assertNull($manager->fromRequest($unknown));

        $empty = \Illuminate\Http\Request::create('/', 'GET');
        $this->assertNull($manager->fromRequest($empty));

        // An expired record is deleted on sight.
        $record->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertNull($manager->fromRequest($request));
        $this->assertSame(0, FlowSession::count());

        [$second] = $manager->start([]);
        $manager->destroy($second);
        $this->assertSame(0, FlowSession::count());

        $cleared = $manager->forgetCookie();
        $this->assertSame('', $cleared->getValue());
    }

    // ------------------------------------------------------------------
    // Support units

    public function test_qr_image_renders_an_svg(): void
    {
        $svg = QrImage::svg('https://easy-refer.com/new/refer/example');
        $this->assertStringStartsWith('<?xml', $svg);
    }

    public function test_hold_reason_sentences_cover_every_rule(): void
    {
        $business = World::business();
        $sentences = HoldReasons::sentences([
            'velocity_daily', 'velocity_monthly', 'device_signal_reuse',
            'referrer_concentration', 'redemption_burst', 'near_total_conversion',
            'unattributed_share', 'sim_swap_step_up', 'something_unknown',
        ], $business);

        $this->assertCount(9, $sentences);
        $this->assertStringContainsString('monthly limit of 50', $sentences[1]);
        // Amendment 32 [PM-REV-2] (Lane B F-3): the SIM-swap
        // sentence is pinned exactly, so the retired fact-only
        // form ("...moved to a new SIM card." and nothing after)
        // cannot return.
        $this->assertSame('This phone number was recently moved to a new SIM card. Someone else may have taken over the number.', $sentences[7]);
        $this->assertSame('Flagged for review by the automatic risk checks.', $sentences[8]);
        $this->assertSame([], HoldReasons::sentences(null));
    }

    // ------------------------------------------------------------------
    // Jobs' failure and edge paths

    public function test_reward_ready_job_records_failure_after_retries(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(15)]);
        [$reward] = World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING, 'notification_state' => 'queued',
            'available_at' => now()->subDay(),
        ]);

        $this->sms->failFor[] = '+12125550999';
        $this->artisan('rewards:notify-due')->assertSuccessful();

        // EC-23: not dropped, not sent; the failure is the record.
        $this->assertSame('failed', $reward->fresh()->notification_state);
        // PIN RE-DERIVATION (ID-07, Amendment 51): this fixture's
        // business is flow-active with a live share token, so the
        // reshare appendage legitimately rides this send and the
        // row carries the RR-M1 stamp 'reward_ready_reshare'
        // (purpose stays 'reward_ready'). The pin's intent (the
        // failure is recorded, not dropped) is unchanged.
        $this->assertDatabaseHas('sms_messages', ['template_key' => 'reward_ready_reshare', 'delivery_state' => 'failed']);
    }

    public function test_jobs_tolerate_missing_rows(): void
    {
        (new SendRewardReadySms(999999))->handle($this->sms);
        (new SendFriendLinkSms(999999))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_friend_link_job_respects_an_opt_out_that_arrived_after_claim(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        SmsOptOut::create(['phone_e164' => $friend->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        (new SendFriendLinkSms($referral->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
    }

    // ------------------------------------------------------------------
    // Blog post page

    public function test_blog_post_page_renders_a_published_post(): void
    {
        $post = Post::create([
            'slug' => 'first-post',
            'title' => 'First Post',
            'excerpt' => 'An excerpt.',
            'body' => 'The body copy.',
            'published_at' => now(),
        ]);

        $this->get(route('blog.post', ['slug' => 'first-post']))
            ->assertOk()
            ->assertSee('First Post')
            ->assertSee('The body copy.');

        $this->get(route('blog.post', ['slug' => 'missing']))->assertNotFound();

        $post->forceFill(['published_at' => null])->save();
        $this->get(route('blog.post', ['slug' => 'first-post']))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Signup prefills and Turnstile on the owner forms

    #[Group('slow')]
    public function test_signup_ticket_prefill_applies_to_the_business(): void
    {
        Mail::fake();
        $this->get(route('register', ['ticket' => 45, 'type' => 'cafe']))->assertOk();

        $this->post(route('register'), $this->registrationPayload())->assertRedirect(route('onboarding.welcome'));

        $business = \App\Models\Business::query()->latest('id')->firstOrFail();
        $this->assertSame(4500, $business->average_ticket_cents);
    }

    public function test_turnstile_failure_blocks_registration_and_password_reset(): void
    {
        Config::set('services.turnstile.site_key', 'site');
        Config::set('services.turnstile.secret_key', 'secret');
        Http::fake(['*' => Http::response(['success' => false], 200)]);
        Mail::fake();

        $this->post(route('register'), $this->registrationPayload())
            ->assertSessionHasErrors();
        $this->assertSame(0, \App\Models\Business::count());

        World::business();
        $this->post(route('password.email'), ['email' => 'owner1@example.test'])
            ->assertSessionHasErrors();
    }

    private function registrationPayload(): array
    {
        return [
            'business_name' => 'Prefill Bakery',
            'name' => 'Pre Fill',
            'email' => 'prefill@example.test',
            'phone' => '(212) 555-0199',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ];
    }
}
