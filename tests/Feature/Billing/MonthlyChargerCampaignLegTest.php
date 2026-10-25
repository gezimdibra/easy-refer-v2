<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Services\Billing\MonthlyCharger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The charger's campaign leg (spec Sections 10.3, 9.5; OD-1):
 * attempted campaign recipients join the same monthly Square
 * payment as a "Text campaigns" line; the $99 cap binds visit
 * charges only; an SMS-only month charges through a
 * campaign_metering record; the $1 minimum reads the combined
 * total; a recipient whose send was never attempted is never
 * billed; record-only mode simulates without claiming.
 */
class MonthlyChargerCampaignLegTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        config(['billing.charges_enabled' => true]);
    }

    private function event(Business $business, Membership $membership, int $amountCents = 200): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        return BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
        ]);
    }

    private function cardOnFile(Business $business): void
    {
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_test',
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_test',
        ]);
    }

    /**
     * Recipient rows on one already-sent campaign. Attempted rows
     * carry their sms_messages evidence link; unattempted rows
     * (skipped / capped out) never do, and are never billed.
     *
     * @param  list<int>  $attemptedAmounts
     * @param  list<int>  $unattemptedAmounts
     * @return list<SmsCampaignRecipient>
     */
    private function recipients(Business $business, array $attemptedAmounts, array $unattemptedAmounts = []): array
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'recipient_count' => count($attemptedAmounts) + count($unattemptedAmounts),
            'segment_count' => 1,
            'metered_cents' => array_sum($attemptedAmounts),
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'sent_at' => now(),
        ]);

        $rows = [];
        $seq = 9100;
        foreach ([...$attemptedAmounts, ...$unattemptedAmounts] as $i => $amount) {
            $attempted = $i < count($attemptedAmounts);
            $customer = World::customer('+1212555'.($seq++), 'Cam', 'Paign');
            [$membership] = World::membership($business, $customer);

            $messageId = null;
            if ($attempted) {
                $messageId = SmsMessage::create([
                    'business_id' => $business->id,
                    'customer_id' => $customer->id,
                    'phone_e164' => $customer->phone_e164,
                    'purpose' => 'campaign',
                    'messaging_service' => 'marketing',
                    'template_key' => 'campaign',
                    'rendered_body' => 'Spring sale is on',
                    'twilio_message_sid' => 'SM_TEST_'.$campaign->id.'_'.$i,
                    'delivery_state' => 'sent',
                    'consent_basis' => 'customer_checkbox',
                    'opt_out_state_at_send' => false,
                ])->id;
            }

            $rows[] = SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'membership_id' => $membership->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'twilio_message_sid' => $attempted ? 'SM_TEST_'.$campaign->id.'_'.$i : null,
                'sms_message_id' => $messageId,
                'delivery_state' => $attempted ? 'sent' : 'skipped',
                'segments' => 1,
                'amount_cents' => $amount,
            ]);
        }

        return $rows;
    }

    public function test_sms_leg_rides_the_same_payment_and_the_cap_stays_visits_only(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $this->cardOnFile($business);
        for ($i = 0; $i < 60; $i++) {
            $this->event($business, $membership);
        }
        $rows = $this->recipients($business, [100, 100, 100, 100, 100]);

        $record = app(MonthlyCharger::class)->charge($business);

        // Visits settle at the cap: 49 x 200 = 9,800. The SMS leg
        // adds 500 on the SAME payment, so the month totals
        // 10,300: the $99 cap never bound the texts.
        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::KIND_PER_REFERRAL_BATCH, $record->kind);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame(10300, $record->amount_cents);
        $this->assertCount(1, $this->gateway->chargeCalls);
        $this->assertSame(10300, $this->gateway->chargeCalls[0]['amount_cents']);
        $lineItems = $this->gateway->chargeCalls[0]['line_items'];
        $this->assertCount(50, $lineItems);
        $this->assertSame('Text campaigns', $lineItems[49]['name']);
        $this->assertSame(500, $lineItems[49]['amount_cents']);
        $this->assertSame(49, BillingEvent::query()->where('billing_record_id', $record->id)->count());
        foreach ($rows as $row) {
            $this->assertSame($record->id, $row->fresh()->billing_record_id);
        }
    }

    public function test_sms_only_month_charges_a_campaign_metering_record(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $this->cardOnFile($business);
        $rows = $this->recipients($business, [200, 200, 200]);

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::KIND_CAMPAIGN_METERING, $record->kind);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame(600, $record->amount_cents);
        $this->assertCount(1, $this->gateway->chargeCalls);
        $this->assertSame(600, $this->gateway->chargeCalls[0]['amount_cents']);
        $this->assertCount(1, $this->gateway->chargeCalls[0]['line_items']);
        $this->assertSame('Text campaigns', $this->gateway->chargeCalls[0]['line_items'][0]['name']);
        foreach ($rows as $row) {
            $this->assertSame($record->id, $row->fresh()->billing_record_id);
        }
    }

    public function test_combined_total_below_the_minimum_carries_everything_forward(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership, 50);
        $rows = $this->recipients($business, [40]);

        $record = app(MonthlyCharger::class)->charge($business);

        // 50 + 40 = 90 cents: below Square's $1 minimum, the whole
        // month carries forward and nothing is claimed.
        $this->assertNull($record);
        $this->assertSame(0, BillingRecord::query()->count());
        $this->assertNull($event->fresh()->billing_record_id);
        $this->assertNull($rows[0]->fresh()->billing_record_id);
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    public function test_sms_can_lift_a_sub_minimum_visit_batch_over_the_minimum(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership, 50);
        $rows = $this->recipients($business, [60]);

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::KIND_PER_REFERRAL_BATCH, $record->kind);
        $this->assertSame(110, $record->amount_cents);
        $this->assertSame($record->id, $event->fresh()->billing_record_id);
        $this->assertSame($record->id, $rows[0]->fresh()->billing_record_id);
    }

    public function test_unattempted_recipient_rows_are_never_billed(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $this->cardOnFile($business);
        $rows = $this->recipients($business, [200, 200, 200], [5000]);

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::KIND_CAMPAIGN_METERING, $record->kind);
        $this->assertSame(600, $record->amount_cents);
        $this->assertNull($rows[3]->fresh()->billing_record_id);
    }

    public function test_simulated_mode_records_the_sms_leg_without_claiming(): void
    {
        config(['billing.charges_enabled' => false]);
        $business = World::business(['free_visits_remaining' => 0]);
        $rows = $this->recipients($business, [200, 200, 200]);

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::KIND_CAMPAIGN_METERING, $record->kind);
        $this->assertSame(BillingRecord::STATE_SIMULATED, $record->state);
        $this->assertSame(600, $record->amount_cents);
        $this->assertCount(0, $this->gateway->chargeCalls);
        foreach ($rows as $row) {
            $this->assertNull($row->fresh()->billing_record_id);
        }
    }

    public function test_statement_shows_the_text_campaigns_line(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $this->cardOnFile($business);
        $this->recipients($business, [200, 200, 200]);

        $record = app(MonthlyCharger::class)->charge($business);
        $this->assertNotNull($record);

        $response = $this->actingAs(World::owner($business))
            ->get(route('settings.billing.statement', $record));

        $response->assertOk();
        $response->assertSee('Text campaigns: $6.00');
        $response->assertSee('Charge: $6.00');
    }
}
