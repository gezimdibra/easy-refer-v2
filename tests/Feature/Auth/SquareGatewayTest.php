<?php

namespace Tests\Feature\Auth;

use App\Contracts\SquareCustomerGateway;
use App\Models\Business;
use App\Services\Square\SdkSquareCustomerGateway;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\CollectingLogger;
use Tests\Fakes\StubSquareTransport;
use Tests\Fakes\StubTransportFailure;
use Tests\TestCase;

/**
 * The real Square customer gateway (spec Sections 4.1 and 9; finding
 * FB-01), exercised entirely through a stubbed PSR-18 transport: no
 * test here touches the network, and no sandbox credentials exist
 * yet. Live verification against the Square sandbox is a Phase 4
 * kickoff gate (spec Amendment 5). What is proven here: the Section
 * 4.1 field mapping on the wire, environment selection from config,
 * and the failure semantics the spec demands (signup completes, the
 * ID stays null, the failure is logged without secrets).
 */
class SquareGatewayTest extends TestCase
{
    use RefreshDatabase;

    private CollectingLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);

        config([
            'services.square.access_token' => 'sq-test-access-token',
            'services.square.environment' => 'sandbox',
        ]);

        $this->log = new CollectingLogger;
        Log::swap($this->log);
    }

    private function successTransport(string $customerId = 'sq_cust_42'): StubSquareTransport
    {
        return new StubSquareTransport(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'customer' => ['id' => $customerId],
        ])));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Corner Deli',
            'name' => 'Dana Owner',
            'email' => 'dana@example.test',
            'phone' => '(212) 555-0147',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ], $overrides);
    }

    public function test_success_maps_the_section_41_fields_and_returns_the_id(): void
    {
        $business = Business::factory()->create([
            'name' => 'Corner Deli',
            'contact_email' => 'dana@example.test',
            'contact_phone_e164' => '+12125550147',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
        ]);

        $transport = $this->successTransport();
        $gateway = new SdkSquareCustomerGateway($transport);

        $this->assertSame('sq_cust_42', $gateway->createCustomerFor($business));

        $request = $transport->lastRequest;
        $this->assertNotNull($request);
        $this->assertSame('connect.squareupsandbox.com', $request->getUri()->getHost());
        $this->assertSame('/v2/customers', $request->getUri()->getPath());
        $this->assertSame('Bearer sq-test-access-token', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('Corner Deli', $body['company_name']);
        $this->assertSame('dana@example.test', $body['email_address']);
        $this->assertSame('+12125550147', $body['phone_number']);
        $this->assertSame('business-'.$business->id, $body['reference_id']);
        $this->assertSame('er2-business-'.$business->id, $body['idempotency_key']);
        $this->assertSame('1 Main Street', $body['address']['address_line_1']);
        $this->assertSame('New York', $body['address']['locality']);
        $this->assertSame('NY', $body['address']['administrative_district_level_1']);
        $this->assertSame('10001', $body['address']['postal_code']);
        $this->assertSame('US', $body['address']['country']);
    }

    public function test_the_production_environment_targets_the_production_host(): void
    {
        config(['services.square.environment' => 'production']);

        $transport = $this->successTransport();
        $gateway = new SdkSquareCustomerGateway($transport);

        $gateway->createCustomerFor(Business::factory()->create());

        $this->assertSame('connect.squareup.com', $transport->lastRequest?->getUri()->getHost());
    }

    public function test_an_api_error_returns_null_and_logs_categories_never_the_token(): void
    {
        $transport = new StubSquareTransport(new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'errors' => [[
                'category' => 'INVALID_REQUEST_ERROR',
                'code' => 'BAD_REQUEST',
                'detail' => 'customer payload echoed here',
            ]],
        ])));

        $gateway = new SdkSquareCustomerGateway($transport);
        $business = Business::factory()->create();

        $this->assertNull($gateway->createCustomerFor($business));

        $errors = $this->log->errors();
        $this->assertCount(1, $errors);
        $this->assertSame(['INVALID_REQUEST_ERROR:BAD_REQUEST'], $errors[0]['context']['square_errors']);
        $this->assertSame($business->id, $errors[0]['context']['business_id']);
        $this->assertStringNotContainsString('sq-test-access-token', (string) json_encode($this->log->records));
        $this->assertStringNotContainsString('customer payload echoed here', (string) json_encode($this->log->records));
    }

    public function test_a_transport_failure_returns_null_and_logs(): void
    {
        $gateway = new SdkSquareCustomerGateway(new StubSquareTransport(new StubTransportFailure('connection timed out')));

        $this->assertNull($gateway->createCustomerFor(Business::factory()->create()));

        $errors = $this->log->errors();
        $this->assertCount(1, $errors);
        $this->assertArrayHasKey('exception', $errors[0]['context']);
    }

    public function test_a_success_response_without_a_customer_returns_null_and_logs(): void
    {
        $transport = new StubSquareTransport(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $gateway = new SdkSquareCustomerGateway($transport);

        $this->assertNull($gateway->createCustomerFor(Business::factory()->create()));
        $this->assertCount(1, $this->log->errors());
    }

    public function test_the_default_transport_is_a_real_psr18_client(): void
    {
        // The production transport (no stub injected) must be a
        // concrete PSR-18 client carrying the configured timeout;
        // building it makes no network call.
        $gateway = new SdkSquareCustomerGateway;
        $method = new \ReflectionMethod($gateway, 'defaultClient');
        $client = $method->invoke($gateway);

        $this->assertInstanceOf(\Psr\Http\Client\ClientInterface::class, $client);
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $client);
    }

    public function test_without_a_configured_token_the_gateway_refuses_cleanly(): void
    {
        config(['services.square.access_token' => '']);

        $gateway = new SdkSquareCustomerGateway($this->successTransport());

        $this->assertNull($gateway->createCustomerFor(Business::factory()->create()));
        $this->assertCount(1, $this->log->errors());
    }

    public function test_location_save_stores_the_customer_id_when_the_sdk_gateway_succeeds(): void
    {
        // RS2-2: the checkpoint creates no Square customer; the
        // Location save completes the deferred provisioning
        // (here the card moment has not happened yet).
        $this->app->instance(SquareCustomerGateway::class, new SdkSquareCustomerGateway($this->successTransport('sq_cust_99')));

        $this->post(route('register'), $this->signupPayload())->assertRedirect(route('onboarding.welcome'));

        $business = Business::firstOrFail();
        $this->assertNull($business->square_customer_id);

        $this->actingAs($business->owner)->post(route('onboarding.location.store'), [
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ])->assertRedirect(route('onboarding.step', 'verify'));

        $this->assertSame('sq_cust_99', $business->fresh()->square_customer_id);
    }

    public function test_signup_completes_with_a_null_customer_when_the_sdk_gateway_errors(): void
    {
        $failing = new StubSquareTransport(new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
            'errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR']],
        ])));
        $this->app->instance(SquareCustomerGateway::class, new SdkSquareCustomerGateway($failing));

        $this->post(route('register'), $this->signupPayload())->assertRedirect(route('onboarding.welcome'));

        $business = Business::firstOrFail();
        $this->assertNull($business->square_customer_id);
        $this->assertNotNull($business->owner);
    }
}
