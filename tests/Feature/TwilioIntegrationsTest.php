<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Membership;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Twilio\TwilioPhoneVerificationGateway;
use App\Services\Twilio\TwilioSmsGateway;
use App\Support\SmsSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Fakes\FakeTwilioHttpClient;
use Tests\Support\World;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * The Twilio integration seams (spec Section 10, twilio-blueprint):
 * gateway outcome mapping over the injectable transport, segment
 * counting, and the webhook's signature discipline, STOP handling,
 * and delivery updates (SMS-16, SMS-17).
 */
class TwilioIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private FakeTwilioHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTwilioHttpClient;
        Config::set('services.twilio.verify_service_sid', 'VA_TEST');
        Config::set('services.twilio.messaging_service_sid', 'MG_TEST');
        Config::set('services.twilio.account_sid', 'AC_TEST');
        Config::set('services.twilio.api_key_sid', 'SK_TEST');
        Config::set('services.twilio.api_key_secret', 'test-secret');
    }

    public function test_verify_start_maps_the_provider_outcomes(): void
    {
        $gateway = new TwilioPhoneVerificationGateway($this->http);

        $this->http->respondWhen('Verifications', 201, ['status' => 'pending', 'sid' => 'VE1']);
        $this->assertSame('started', $gateway->start('+12125550111')['outcome']);

        $this->http->responses = [];
        $this->http->respondWhen('Verifications', 400, ['code' => 60205, 'message' => 'landline']);
        $this->assertSame('landline_blocked', $gateway->start('+12125550111')['outcome']);

        $this->http->responses = [];
        $this->http->respondWhen('Verifications', 429, ['code' => 60203, 'message' => 'too many']);
        $this->assertSame('rate_limited', $gateway->start('+12125550111')['outcome']);

        $this->http->responses = [];
        $this->http->respondWhen('Verifications', 500, ['code' => 99999, 'message' => 'boom']);
        $this->assertSame('provider_error', $gateway->start('+12125550111')['outcome']);
    }

    public function test_verify_check_maps_status_and_hides_expiry(): void
    {
        $gateway = new TwilioPhoneVerificationGateway($this->http);

        $this->http->respondWhen('VerificationCheck', 201, ['status' => 'approved', 'sid' => 'VE1']);
        $this->assertSame('approved', $gateway->check('+12125550111', '123456')['outcome']);

        $this->http->responses = [];
        $this->http->respondWhen('VerificationCheck', 201, ['status' => 'pending', 'sid' => 'VE1']);
        $this->assertSame('pending', $gateway->check('+12125550111', '000000')['outcome']);

        // An expired verification (404/20404) is a denial, never a
        // distinguishable state (SMS-08).
        $this->http->responses = [];
        $this->assertSame('denied', $gateway->check('+12125550111', '123456')['outcome']);
    }

    public function test_sms_gateway_returns_the_sid_and_null_on_failure(): void
    {
        $gateway = new TwilioSmsGateway($this->http);

        $this->http->respondWhen('Messages', 201, ['sid' => 'SM123', 'status' => 'queued']);
        $this->assertSame('SM123', $gateway->send('+12125550111', 'hello'));

        $this->http->responses = [];
        $this->http->respondWhen('Messages', 400, ['code' => 21211, 'message' => 'invalid']);
        $this->assertNull($gateway->send('+12125550111', 'hello'));
    }

    public function test_segment_counting_follows_gsm7_and_ucs2(): void
    {
        $this->assertSame(1, SmsSegments::count(str_repeat('a', 160)));
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 161)));
        $this->assertSame(1, SmsSegments::count(str_repeat('a', 70).'€')); // euro is GSM-7 extension, body stays GSM-7
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 69).'😀')); // emoji forces UCS-2: 69 + 2 units over the 70 limit
        $this->assertSame(1, SmsSegments::count('backtick ` is not basic GSM-7 in this app: '.str_repeat('b', 20)));
    }

    public function test_webhook_without_a_configured_token_rejects_everything(): void
    {
        Config::set('services.twilio.webhook_auth_token', '');

        $this->post(route('webhooks.twilio.status'), ['MessageSid' => 'SM1', 'MessageStatus' => 'delivered'])
            ->assertForbidden();
    }

    public function test_webhook_rejects_a_forged_signature_and_changes_nothing(): void
    {
        Config::set('services.twilio.webhook_auth_token', 'test-auth-token');
        $business = World::business();
        $message = SmsMessage::create([
            'business_id' => $business->id,
            'phone_e164' => '+12125550111',
            'purpose' => 'reward_ready',
            'template_key' => 'reward_ready',
            'rendered_body' => 'x',
            'twilio_message_sid' => 'SM_FORGED',
            'delivery_state' => 'sent',
        ]);

        $this->call('POST', route('webhooks.twilio.status'), ['MessageSid' => 'SM_FORGED', 'MessageStatus' => 'delivered'], [], [], [
            'HTTP_X_TWILIO_SIGNATURE' => 'forged',
        ])->assertForbidden();

        $this->assertSame('sent', $message->fresh()->delivery_state);
    }

    public function test_webhook_applies_delivery_status_for_a_valid_signature(): void
    {
        Config::set('services.twilio.webhook_auth_token', 'test-auth-token');
        $business = World::business();
        $message = SmsMessage::create([
            'business_id' => $business->id,
            'phone_e164' => '+12125550111',
            'purpose' => 'reward_ready',
            'template_key' => 'reward_ready',
            'rendered_body' => 'x',
            'twilio_message_sid' => 'SM_OK',
            'delivery_state' => 'sent',
        ]);

        $params = ['MessageSid' => 'SM_OK', 'MessageStatus' => 'delivered'];
        $this->signedPost($params)->assertOk();
        $this->assertSame('delivered', $message->fresh()->delivery_state);
    }

    public function test_stop_writes_the_platform_opt_out_and_flips_consent(): void
    {
        Config::set('services.twilio.webhook_auth_token', 'test-auth-token');
        $business = World::business();
        $customer = World::customer('+12125550111');
        [$membership] = World::membership($business, $customer);
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        $this->signedPost(['From' => '+12125550111', 'Body' => 'STOP'])->assertOk();

        $this->assertTrue(SmsOptOut::optedOut('+12125550111'));
        $this->assertFalse($membership->fresh()->sms_consent);
        $this->assertDatabaseHas('consent_events', [
            'membership_id' => $membership->id,
            'event_type' => 'revoked',
            'source' => 'customer_reply',
        ]);
    }

    private function signedPost(array $params): \Illuminate\Testing\TestResponse
    {
        $url = route('webhooks.twilio.status');
        $validator = new RequestValidator('test-auth-token');
        $signature = $validator->computeSignature($url, $params);

        return $this->call('POST', $url, $params, [], [], [
            'HTTP_X_TWILIO_SIGNATURE' => $signature,
        ]);
    }
}
