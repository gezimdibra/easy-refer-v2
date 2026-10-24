<?php

namespace Tests\Feature\Billing;

use App\Models\WebhookEvent;
use App\Services\Billing\WebhookEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook dedupe and retention pins (spec Section 9.4; BILL-24,
 * BILL-25, BILL-30): the first verified delivery is recorded,
 * duplicates and replays insert nothing, and summaries are
 * redacted on the way in (no full buyer email, no card digits).
 */
class WebhookEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_delivery_is_recorded_and_duplicates_are_ignored(): void
    {
        $recorder = app(WebhookEventRecorder::class);

        $first = $recorder->recordIfNew('square', 'evt_1', 'payment.updated', true, ['payment_id' => 'pay_1']);
        $duplicate = $recorder->recordIfNew('square', 'evt_1', 'payment.updated', true, ['payment_id' => 'pay_1']);
        $replayAnHourLater = $recorder->recordIfNew('square', 'evt_1', 'payment.updated', true, ['payment_id' => 'pay_1']);

        $this->assertNotNull($first);
        $this->assertNull($duplicate);
        $this->assertNull($replayAnHourLater);
        $this->assertSame(1, WebhookEvent::count());
        $this->assertTrue($first->signature_verified);
        $this->assertSame('payment.updated', $first->event_type);
    }

    public function test_same_event_id_under_another_provider_is_a_different_event(): void
    {
        $recorder = app(WebhookEventRecorder::class);

        $recorder->recordIfNew('square', 'evt_1', 'payment.updated', true);
        $other = $recorder->recordIfNew('twilio', 'evt_1', 'message.delivered', true);

        $this->assertNotNull($other);
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_summaries_are_redacted_on_the_way_in(): void
    {
        $recorder = app(WebhookEventRecorder::class);

        $row = $recorder->recordIfNew('square', 'evt_2', 'payment.created', true, [
            'buyer_email' => 'jane.customer@example.com',
            'note' => 'card 4111111111111111 was charged',
            'nested' => ['contact' => 'reach bob@shop.test please'],
            'amount_cents' => 200,
        ]);

        $summary = $row->payload_summary;
        $this->assertSame('j***@example.com', $summary['buyer_email']);
        $this->assertSame('card [redacted] was charged', $summary['note']);
        $this->assertSame('reach b***@shop.test please', $summary['nested']['contact']);
        $this->assertSame(200, $summary['amount_cents']);
        $this->assertStringNotContainsString('jane.customer', (string) json_encode($summary));
    }
}
