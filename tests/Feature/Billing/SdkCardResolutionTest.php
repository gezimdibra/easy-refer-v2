<?php

namespace Tests\Feature\Billing;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Billing\SquareOutcomeUnknown;
use App\Services\Square\SdkSquarePaymentsGateway;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tests\Support\World;
use Tests\TestCase;

/**
 * A one-shot PSR-18 transport for the card-list read, local to
 * this file (the same stub pattern as the payments gateway
 * test): each request pops the next canned outcome.
 */
final class CardListTransportStub implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $outcomes;

    public function __construct(array $outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof ClientExceptionInterface) {
            throw $outcome;
        }

        return $outcome ?? new Response(500, [], '{}');
    }
}

final class CardListTransportFailure extends RuntimeException implements ClientExceptionInterface {}

/**
 * The SDK gateway's pending-card read (billing UI spec Section
 * 3, build unit BU-4): a card save whose outcome was unknown is
 * resolved by listing the customer's cards at Square. A card is
 * adopted only when it is the single card Square holds that no
 * local row mirrors and that was created no earlier than the
 * pending save (a small clock-skew allowance aside). Anything
 * less definite, an API error, a transport fault, two candidate
 * cards, is unknown, never a guess.
 */
class SdkCardResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.square.access_token' => 'sq-test-token',
            'services.square.environment' => 'sandbox',
            'services.square.location_id' => 'LOC_TEST',
        ]);
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }

    private function cardPayload(string $id, string $createdAt, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'card_brand' => 'VISA',
            'last_4' => '1111',
            'exp_month' => 1,
            'exp_year' => 2031,
            'customer_id' => 'sq_cust_1',
            'created_at' => $createdAt,
            'enabled' => true,
        ], $overrides);
    }

    private function pendingMethod(Business $business, int $minutesAgo = 30): PaymentMethod
    {
        $method = PaymentMethod::create([
            'business_id' => $business->id,
            'state' => PaymentMethod::STATE_PENDING,
            'is_default' => false,
        ]);
        $method->forceFill([
            'idempotency_key' => 'card_'.$business->id.'_'.$method->id,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->save();

        return $method;
    }

    public function test_adopts_the_single_unmirrored_card_created_for_the_save(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_old',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_old',
        ]);
        $method = $this->pendingMethod($business);
        $transport = new CardListTransportStub([
            $this->jsonResponse(['cards' => [
                $this->cardPayload('card_old', now()->subDays(2)->utc()->toRfc3339String()),
                $this->cardPayload('card_new', now()->subMinutes(29)->utc()->toRfc3339String()),
            ]]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $result = $gateway->resolveCard($method);

        $this->assertNotNull($result);
        $this->assertTrue($result->succeeded);
        $this->assertSame('card_new', $result->cardId);
        $this->assertSame('VISA', $result->brand);
        $this->assertSame('1111', $result->last4);
        $this->assertSame(1, $result->expMonth);
        $this->assertSame(2031, $result->expYear);
        $this->assertStringContainsString('customer_id=sq_cust_1', (string) $transport->requests[0]->getUri());
    }

    public function test_returns_null_when_square_holds_no_unmirrored_card(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_old',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_old',
        ]);
        $method = $this->pendingMethod($business);
        $transport = new CardListTransportStub([
            $this->jsonResponse(['cards' => [
                $this->cardPayload('card_old', now()->subDays(2)->utc()->toRfc3339String()),
            ]]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $this->assertNull($gateway->resolveCard($method));
    }

    public function test_a_card_created_before_the_pending_save_is_not_adopted(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingMethod($business, minutesAgo: 30);
        $transport = new CardListTransportStub([
            $this->jsonResponse(['cards' => [
                $this->cardPayload('card_stray', now()->subHours(3)->utc()->toRfc3339String()),
            ]]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $this->assertNull($gateway->resolveCard($method));
    }

    public function test_two_candidate_cards_are_unknown_never_a_guess(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingMethod($business);
        $transport = new CardListTransportStub([
            $this->jsonResponse(['cards' => [
                $this->cardPayload('card_a', now()->subMinutes(29)->utc()->toRfc3339String()),
                $this->cardPayload('card_b', now()->subMinutes(28)->utc()->toRfc3339String()),
            ]]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $this->expectException(SquareOutcomeUnknown::class);
        $gateway->resolveCard($method);
    }

    public function test_a_transport_failure_is_unknown(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingMethod($business);
        $gateway = new SdkSquarePaymentsGateway(new CardListTransportStub([
            new CardListTransportFailure('timeout'),
        ]));

        $this->expectException(SquareOutcomeUnknown::class);
        $gateway->resolveCard($method);
    }

    public function test_an_api_error_on_the_list_is_unknown_not_a_failure_of_the_card(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingMethod($business);
        $gateway = new SdkSquarePaymentsGateway(new CardListTransportStub([
            $this->jsonResponse(['errors' => [[
                'category' => 'AUTHENTICATION_ERROR',
                'code' => 'UNAUTHORIZED',
                'detail' => 'bad token',
            ]]], 401),
        ]));

        $this->expectException(SquareOutcomeUnknown::class);
        $gateway->resolveCard($method);
    }

    public function test_without_a_token_the_resolution_is_a_definitive_failure(): void
    {
        config(['services.square.access_token' => null]);
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingMethod($business);
        $gateway = new SdkSquarePaymentsGateway(new CardListTransportStub([]));

        $result = $gateway->resolveCard($method);

        $this->assertNotNull($result);
        $this->assertFalse($result->succeeded);
        $this->assertSame('square_not_configured', $result->errorCode);
    }

    public function test_a_business_without_a_square_customer_holds_nothing(): void
    {
        $business = World::business(['square_customer_id' => null]);
        $method = $this->pendingMethod($business);
        $gateway = new SdkSquarePaymentsGateway(new CardListTransportStub([]));

        $this->assertNull($gateway->resolveCard($method));
    }
}
