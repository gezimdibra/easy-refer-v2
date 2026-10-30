<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Services\Billing\ChargeResult;
use App\Services\Billing\PendingRecordSweeper;
use App\Services\Billing\SquareWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Money fix train, fix 3 (business-logic audit 2.3 and 5.4;
 * synthesis item 3): the stranded text leg.
 *
 * The charger's own definitive-failure path releases both legs
 * of a failed batch. Before this train, the two asynchronous
 * resolution paths released only the visit leg:
 * PendingRecordSweeper (superseded and failed branches) and
 * the Square webhook FAILED path updated BillingEvent rows
 * only, so campaign recipient rows claimed under a record that
 * resolved late stayed claimed forever: never re-billed, never
 * on any statement, the platform silently absorbing the cost.
 * Both paths now release the text leg symmetrically.
 */
class StrandedTextLegTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $sequence = 4000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        config(['billing.charges_enabled' => true]);
    }

    /**
     * A pending record with one claimed visit event and one
     * claimed campaign recipient row.
     *
     * @return array{0: BillingRecord, 1: BillingEvent, 2: SmsCampaignRecipient}
     */
    private function pendingRecordWithBothLegs(Business $business): array
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 300,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_strand_'.(++$this->sequence),
            'state' => BillingRecord::STATE_PENDING,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);
        $record->forceFill(['created_at' => now()->subHour()])->save();

        $referrer = World::customer('+12125550987', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+1212555'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'billing_record_id' => $record->id,
            'amount_cents' => 200,
            'coverage' => 'billable',
        ]);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'recipient_count' => 1,
            'segment_count' => 1,
            'metered_cents' => 100,
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'sent_at' => now(),
        ]);
        $customer = World::customer('+1212555'.str_pad((string) ($this->sequence + 500), 4, '0', STR_PAD_LEFT), 'Cam', 'Paign');
        [$camMembership] = World::membership($business, $customer);
        $message = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'purpose' => 'campaign',
            'messaging_service' => 'marketing',
            'template_key' => 'campaign',
            'rendered_body' => 'Spring sale is on',
            'twilio_message_sid' => 'SM_STRAND_'.$this->sequence,
            'delivery_state' => 'sent',
            'consent_basis' => 'customer_checkbox',
            'opt_out_state_at_send' => false,
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $camMembership->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'twilio_message_sid' => 'SM_STRAND_'.$this->sequence,
            'sms_message_id' => $message->id,
            'delivery_state' => 'sent',
            'segments' => 1,
            'amount_cents' => 100,
            'billing_record_id' => $record->id,
        ]);

        return [$record, $event, $row];
    }

    public function test_sweeper_supersede_releases_the_text_leg_with_the_visit_leg(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        [$record, $event, $row] = $this->pendingRecordWithBothLegs($business);

        // Square holds nothing for the key: supersede.
        $summary = app(PendingRecordSweeper::class)->sweep(15);

        $this->assertSame(1, $summary['superseded']);
        $this->assertSame(BillingRecord::STATE_SUPERSEDED, $record->fresh()->state);
        $this->assertNull($event->fresh()->billing_record_id);
        $this->assertNull($row->fresh()->billing_record_id);
    }

    public function test_sweeper_failure_releases_the_text_leg_with_the_visit_leg(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        [$record, $event, $row] = $this->pendingRecordWithBothLegs($business);
        $this->gateway->resolvedCharges[$record->idempotency_key] = ChargeResult::failed('CARD_DECLINED', 'api');

        $summary = app(PendingRecordSweeper::class)->sweep(15);

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(BillingRecord::STATE_FAILED, $record->fresh()->state);
        $this->assertNull($event->fresh()->billing_record_id);
        $this->assertNull($row->fresh()->billing_record_id);
    }

    public function test_sweeper_adoption_keeps_both_legs_claimed(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        [$record, $event, $row] = $this->pendingRecordWithBothLegs($business);
        $this->gateway->resolvedCharges[$record->idempotency_key] = ChargeResult::succeeded('sq_payment_adopted', 'sq_order_adopted');

        $summary = app(PendingRecordSweeper::class)->sweep(15);

        $this->assertSame(1, $summary['adopted']);
        $this->assertSame($record->id, $event->fresh()->billing_record_id);
        $this->assertSame($record->id, $row->fresh()->billing_record_id);
    }

    public function test_webhook_failed_payment_releases_the_text_leg_with_the_visit_leg(): void
    {
        $business = World::business([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
        ]);
        [$record, $event, $row] = $this->pendingRecordWithBothLegs($business);

        app(SquareWebhookHandler::class)->handle([
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'sq_pay_failed', 'status' => 'FAILED', 'customer_id' => 'sq_customer_1',
            ]]],
        ]);

        $this->assertSame(BillingRecord::STATE_FAILED, $record->fresh()->state);
        $this->assertNull($event->fresh()->billing_record_id);
        $this->assertNull($row->fresh()->billing_record_id);
        $this->assertSame('past_due', $business->fresh()->billing_status);
    }

    public function test_released_text_rows_bill_on_the_next_charge_run(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        [$record, , $row] = $this->pendingRecordWithBothLegs($business);
        $this->gateway->resolvedCharges[$record->idempotency_key] = ChargeResult::failed('CARD_DECLINED', 'api');
        app(PendingRecordSweeper::class)->sweep(15);
        $this->assertNull($row->fresh()->billing_record_id);

        \App\Models\PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_test',
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_strand',
        ]);

        $next = app(\App\Services\Billing\MonthlyCharger::class)->charge($business->fresh());

        $this->assertNotNull($next);
        $this->assertSame($next->id, $row->fresh()->billing_record_id);
    }
}
