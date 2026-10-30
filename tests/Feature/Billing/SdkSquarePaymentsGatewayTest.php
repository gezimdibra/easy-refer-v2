<?php

namespace Tests\Feature\Billing;

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
 * A queueing PSR-18 transport for multi-call Square flows: each
 * request pops the next canned outcome and is recorded, so the
 * test can assert the exact wire sequence (order, then payment).
 */
final class QueuedSquareTransport implements ClientInterface
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

/**
 * A transport-level failure (timeout, DNS, refused connection) in
 * PSR-18 clothing, local to this test file.
 */
final class QueuedTransportFailure extends RuntimeException implements ClientExceptionInterface {}

/**
 * The real SDK payments gateway (spec Section 9, blueprint
 * Section 1), exercised entirely through stubbed transports: no
 * network, no credentials. What is proven: the wire shape (an
 * Order with one line item per event, then a Payment against the
 * card on file with the batch key), definitive API failures
 * mapping to failed results with Square's code, and transport
 * failures mapping to SquareOutcomeUnknown, never to a result.
 */
class SdkSquarePaymentsGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.square.access_token' => 'sq-test-access-token',
            'services.square.environment' => 'sandbox',
            'services.square.location_id' => 'sq_location_1',
        ]);
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }

    private function cardFor($business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_1',
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);
    }

    public function test_charge_builds_an_order_then_a_payment_and_maps_the_ids(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $transport = new QueuedSquareTransport([
            $this->jsonResponse(['order' => ['id' => 'sq_order_1', 'version' => 1, 'location_id' => 'sq_location_1']]),
            $this->jsonResponse(['payment' => ['id' => 'sq_payment_1', 'status' => 'COMPLETED']]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $result = $gateway->chargeCardOnFile($business, $this->cardFor($business), 400, 'perref_1_2026-03-01_1', [
            ['name' => 'Referral fee', 'note' => 'referral 11', 'amount_cents' => 200],
            ['name' => 'Referral fee', 'note' => 'referral 12', 'amount_cents' => 200],
        ]);

        $this->assertTrue($result->succeeded);
        $this->assertSame('sq_payment_1', $result->paymentId);
        $this->assertSame('sq_order_1', $result->orderId);

        $this->assertCount(2, $transport->requests);
        $orderBody = json_decode((string) $transport->requests[0]->getBody(), true);
        $this->assertSame('perref_1_2026-03-01_1_order', $orderBody['idempotency_key']);
        $this->assertCount(2, $orderBody['order']['line_items']);
        $this->assertSame(200, $orderBody['order']['line_items'][0]['base_price_money']['amount']);
        $this->assertSame('sq_location_1', $orderBody['order']['location_id']);

        $paymentBody = json_decode((string) $transport->requests[1]->getBody(), true);
        $this->assertSame('perref_1_2026-03-01_1', $paymentBody['idempotency_key']);
        $this->assertSame('sq_card_1', $paymentBody['source_id']);
        $this->assertSame('sq_order_1', $paymentBody['order_id']);
        $this->assertSame(400, $paymentBody['amount_money']['amount']);
        $this->assertSame('USD', $paymentBody['amount_money']['currency']);
    }

    public function test_declined_payment_maps_to_a_failed_result_with_squares_code(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $transport = new QueuedSquareTransport([
            $this->jsonResponse(['order' => ['id' => 'sq_order_1', 'version' => 1, 'location_id' => 'sq_location_1']]),
            $this->jsonResponse(['errors' => [[
                'category' => 'PAYMENT_METHOD_ERROR',
                'code' => 'GENERIC_DECLINE',
                'detail' => 'The card was declined.',
            ]]], 400),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $result = $gateway->chargeCardOnFile($business, $this->cardFor($business), 200, 'perref_1_2026-03-01_1', [
            ['name' => 'Referral fee', 'note' => null, 'amount_cents' => 200],
        ]);

        $this->assertFalse($result->succeeded);
        $this->assertSame('GENERIC_DECLINE', $result->errorCode);
        $this->assertSame('PAYMENT_METHOD_ERROR', $result->errorCategory);
    }

    public function test_transport_failure_is_an_unknown_outcome_never_a_result(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $transport = new QueuedSquareTransport([new QueuedTransportFailure('timeout')]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $this->expectException(SquareOutcomeUnknown::class);
        $gateway->chargeCardOnFile($business, $this->cardFor($business), 200, 'perref_1_2026-03-01_1', [
            ['name' => 'Referral fee', 'note' => null, 'amount_cents' => 200],
        ]);
    }

    public function test_save_card_maps_the_response_fields(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $transport = new QueuedSquareTransport([
            $this->jsonResponse(['card' => [
                'id' => 'sq_card_9',
                'card_brand' => 'MASTERCARD',
                'last_4' => '5555',
                'exp_month' => 3,
                'exp_year' => 2031,
                'customer_id' => 'sq_cust_1',
            ]]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $result = $gateway->saveCard($business, 'single-use-token', 'card_1_1');

        $this->assertTrue($result->succeeded);
        $this->assertSame('sq_card_9', $result->cardId);
        $this->assertSame('MASTERCARD', $result->brand);
        $this->assertSame('5555', $result->last4);
        $this->assertSame(3, $result->expMonth);
        $this->assertSame(2031, $result->expYear);

        $body = json_decode((string) $transport->requests[0]->getBody(), true);
        $this->assertSame('single-use-token', $body['source_id']);
        $this->assertSame('sq_cust_1', $body['card']['customer_id']);
    }

    public function test_refund_maps_the_refund_id(): void
    {
        $transport = new QueuedSquareTransport([
            $this->jsonResponse(['refund' => ['id' => 'sq_refund_1', 'status' => 'PENDING', 'amount_money' => ['amount' => 200, 'currency' => 'USD']]]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $result = $gateway->refundPayment('sq_payment_1', 200, 'refund_1_1', 'owner_request');

        $this->assertTrue($result->succeeded);
        $this->assertSame('sq_refund_1', $result->refundId);

        $body = json_decode((string) $transport->requests[0]->getBody(), true);
        $this->assertSame('sq_payment_1', $body['payment_id']);
        $this->assertSame(200, $body['amount_money']['amount']);
    }

    public function test_missing_token_is_a_clean_failure_not_an_exception(): void
    {
        config(['services.square.access_token' => null]);
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $gateway = new SdkSquarePaymentsGateway(new QueuedSquareTransport([]));

        $result = $gateway->chargeCardOnFile($business, $this->cardFor($business), 200, 'perref_1_2026-03-01_1', []);

        $this->assertFalse($result->succeeded);
        $this->assertSame('square_not_configured', $result->errorCode);
    }

    /**
     * FE-BILL-01: a record also claims the month's free and
     * over-cap coverage rows (for its statement), but a
     * sweeper resolution must rebuild the ORIGINAL charge:
     * its order line items are the billed visits only, so
     * they still sum to the record's amount.
     */
    public function test_resolve_charge_line_items_are_the_billed_visits_only(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $card = $this->cardFor($business);
        $record = \App\Models\BillingRecord::create([
            'business_id' => $business->id,
            'kind' => \App\Models\BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_2026-09-30_1',
            'payment_method_id' => $card->id,
            'state' => \App\Models\BillingRecord::STATE_PENDING,
            'period_start' => '2026-09-03',
            'period_end' => '2026-09-24',
        ]);

        $referrer = World::customer('+12125550986', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        foreach (['free_credit', 'billable', 'over_cap'] as $i => $coverage) {
            $friend = World::customer('+121255500'.(70 + $i), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            \App\Models\BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'billing_record_id' => $record->id,
                'amount_cents' => 200,
                'coverage' => $coverage,
            ]);
        }

        $transport = new QueuedSquareTransport([
            $this->jsonResponse(['order' => ['id' => 'sq_order_9', 'version' => 1, 'location_id' => 'sq_location_1']]),
            $this->jsonResponse(['payment' => ['id' => 'sq_payment_9', 'status' => 'COMPLETED']]),
        ]);
        $gateway = new SdkSquarePaymentsGateway($transport);

        $result = $gateway->resolveCharge($record);

        $this->assertNotNull($result);
        $this->assertTrue($result->succeeded);
        $orderBody = json_decode((string) $transport->requests[0]->getBody(), true);
        $this->assertCount(1, $orderBody['order']['line_items']);
        $paymentBody = json_decode((string) $transport->requests[1]->getBody(), true);
        $this->assertSame(200, $paymentBody['amount_money']['amount']);
    }
}
