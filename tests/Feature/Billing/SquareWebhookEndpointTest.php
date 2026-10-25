<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\WebhookEvent;
use App\Services\Billing\SquareWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The Square webhook endpoint (spec Section 9.4): the raw body
 * is verified against the registered URL before anything is
 * parsed, recorded, or changed. A failed verification returns
 * an error, is counted, and changes no state; a verified
 * delivery is recorded once (duplicates are acknowledged and
 * ignored) and handled.
 */
class SquareWebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE_KEY = 'whsec_test_key_for_endpoint_tests';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
        config(['services.square.webhook_signature_key' => self::SIGNATURE_KEY]);
    }

    /** @param array<string, mixed> $event */
    private function deliver(array $event, ?string $signatureKey = self::SIGNATURE_KEY, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($event);
        $signature ??= $signatureKey === null
            ? 'unsigned'
            : SquareWebhookSignature::sign($signatureKey, route('webhooks.square'), $body);

        return $this->call('POST', route('webhooks.square'), [], [], [], [
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * Square's real delivery envelope (the shape Square actually
     * sends, proven live on release 13: the delivery id is the
     * top-level `event_id`, never `id`; the object rides under
     * data{type, id, object}). Every fixture in this file uses
     * this shape; the pre-fix suite modeled a top-level `id`,
     * an envelope Square never sends, so 20 green tests never
     * exercised the real delivery path.
     *
     * @return array<string, mixed>
     */
    private function completedPaymentEvent(string $eventId, string $paymentId): array
    {
        return [
            'merchant_id' => 'MLTESTMERCHANT1',
            'location_id' => 'LTESTLOCATION1',
            'type' => 'payment.updated',
            'event_id' => $eventId,
            'created_at' => '2026-10-24T20:06:51-04:00',
            'data' => [
                'type' => 'payment',
                'id' => $paymentId,
                'object' => ['payment' => [
                    'id' => $paymentId, 'status' => 'COMPLETED', 'customer_id' => 'sq_customer_1',
                ]],
            ],
        ];
    }

    private function pastDueBusinessWithRecord(): Business
    {
        $business = World::business([
            'billing_status' => 'past_due',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
        ]);
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_2026-03-01_1',
            'square_payment_id' => 'sq_pay_1',
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        return $business;
    }

    public function test_unsigned_delivery_is_rejected_and_changes_nothing(): void
    {
        $business = $this->pastDueBusinessWithRecord();

        $this->call('POST', route('webhooks.square'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($this->completedPaymentEvent('evt_unsigned', 'sq_pay_1')))->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame('past_due', $business->fresh()->billing_status);
    }

    public function test_forged_signature_is_rejected_counted_and_changes_nothing(): void
    {
        $business = $this->pastDueBusinessWithRecord();

        $this->deliver($this->completedPaymentEvent('evt_forged', 'sq_pay_1'), signature: 'forged')->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame('past_due', $business->fresh()->billing_status);
        $this->assertSame(1, (int) Cache::get('square_webhook_signature_failures', 0));
    }

    public function test_no_configured_key_rejects_everything(): void
    {
        config(['services.square.webhook_signature_key' => '']);

        $this->deliver($this->completedPaymentEvent('evt_nokey', 'sq_pay_1'))->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_verified_delivery_is_recorded_handled_and_acknowledged(): void
    {
        $business = $this->pastDueBusinessWithRecord();

        $this->deliver($this->completedPaymentEvent('evt_ok', 'sq_pay_1'))->assertOk();

        $row = WebhookEvent::query()->where('event_id', 'evt_ok')->firstOrFail();
        $this->assertSame('square', $row->provider);
        $this->assertTrue($row->signature_verified);
        $this->assertNotNull($row->processed_at);
        $this->assertSame('active', $business->fresh()->billing_status);
    }

    public function test_the_release_13_test_event_shape_records_a_row(): void
    {
        // The exact delivery class that exposed the defect live
        // (release 13, item 3): Square's subscription test event
        // re-fired with status 200 and a verified signature, yet
        // no webhook_events row existed afterwards, because the
        // controller read the delivery id from a top-level `id`
        // Square never sends. The payment names no local record,
        // so the handler acknowledges it (Critical-logged per
        // spec 9.4) — but the row must exist and be processed.
        $this->deliver([
            'merchant_id' => 'MLTESTMERCHANT1',
            'location_id' => 'LTESTLOCATION1',
            'type' => 'payment.updated',
            'event_id' => 'evt_square_test_event',
            'created_at' => '2026-10-24T20:06:51-04:00',
            'data' => [
                'type' => 'payment',
                'id' => 'sq_pay_unmatched',
                'object' => ['payment' => [
                    'id' => 'sq_pay_unmatched', 'status' => 'COMPLETED', 'customer_id' => 'sq_customer_nobody',
                ]],
            ],
        ])->assertOk();

        $row = WebhookEvent::query()->where('event_id', 'evt_square_test_event')->firstOrFail();
        $this->assertSame('payment.updated', $row->event_type);
        $this->assertTrue($row->signature_verified);
        $this->assertNotNull($row->processed_at);
    }

    public function test_duplicate_delivery_is_acknowledged_and_handled_once(): void
    {
        $business = $this->pastDueBusinessWithRecord();
        $event = $this->completedPaymentEvent('evt_dup', 'sq_pay_1');

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.reactivated')->count());
        $this->assertSame('active', $business->fresh()->billing_status);
    }

    public function test_verified_delivery_of_an_unhandled_type_is_recorded_and_acknowledged(): void
    {
        $this->deliver([
            'merchant_id' => 'MLTESTMERCHANT1',
            'location_id' => 'LTESTLOCATION1',
            'type' => 'payout.sent',
            'event_id' => 'evt_payout',
            'created_at' => '2026-10-24T20:06:51-04:00',
            'data' => ['type' => 'payout', 'id' => 'sq_payout_1', 'object' => []],
        ])->assertOk();

        $row = WebhookEvent::query()->where('event_id', 'evt_payout')->firstOrFail();
        $this->assertNotNull($row->processed_at);
        $this->assertSame(0, AuditLog::count());
    }
}
