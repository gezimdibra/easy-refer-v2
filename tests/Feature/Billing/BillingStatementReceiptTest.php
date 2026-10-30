<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Services\Billing\MonthlyCharger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * BS-1..BS-4 (gap pack 04, Variant A; Copy Amendment 29
 * [BS-1]): the statement reads as a receipt. The H1 names the
 * period, a Paid pill states the record's fact, the charge
 * renders at tile-value scale and equals the record amount to
 * the cent, the coverage rows carry their ratified one-line
 * explanations with $0.00 shown explicitly on the covered rows,
 * and a total row repeats the charge so the rows visibly sum
 * to it. Campaign-only records lead with the texts row and
 * collapse the visit rows to the single ratified line. The
 * estimate stays quarantined in its own panel, and no
 * breadcrumb renders.
 *
 * Every fixture record is produced by running MonthlyCharger
 * over real rows (the FE-BILL-01 test discipline): never a
 * hand-linked statement fixture. Presentation only: counts
 * come from the record's claimed coverage ledger, text cents
 * from its claimed recipient rows, the charge is the record
 * amount.
 */
class BillingStatementReceiptTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 9300;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        config(['billing.charges_enabled' => true]);
    }

    private function card(Business $business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_test_bs',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_bs',
        ]);
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550989', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function visit(Business $business, Membership $membership, ?string $at = null): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => 200,
        ]);
        if ($at !== null) {
            $event->forceFill(['created_at' => $at])->save();
        }

        return $event;
    }

    /**
     * Attempted recipient rows on one sent campaign (the
     * MonthlyChargerCampaignLegTest shape): each carries its
     * sms_messages evidence link, so the charger claims it.
     *
     * @param  list<int>  $amounts
     */
    private function attemptedRecipients(Business $business, array $amounts): void
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'recipient_count' => count($amounts),
            'segment_count' => 1,
            'metered_cents' => array_sum($amounts),
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'sent_at' => now(),
        ]);

        $seq = 9400;
        foreach ($amounts as $i => $amount) {
            $customer = World::customer('+1212555'.($seq++), 'Cam', 'Paign');
            [$membership] = World::membership($business, $customer);

            $messageId = SmsMessage::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'purpose' => 'campaign',
                'messaging_service' => 'marketing',
                'template_key' => 'campaign',
                'rendered_body' => 'Spring sale is on',
                'twilio_message_sid' => 'SM_BS_'.$campaign->id.'_'.$i,
                'delivery_state' => 'sent',
                'consent_basis' => 'customer_checkbox',
                'opt_out_state_at_send' => false,
            ])->id;

            SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'membership_id' => $membership->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'twilio_message_sid' => 'SM_BS_'.$campaign->id.'_'.$i,
                'sms_message_id' => $messageId,
                'delivery_state' => 'sent',
                'segments' => 1,
                'amount_cents' => $amount,
            ]);
        }
    }

    /**
     * The full coverage mix through the charger (the
     * BillingStatementTest September shape): 1 free, 2 charged,
     * 1 over cap under a $4.00 cap plan; record amount $4.00.
     */
    private function septemberRecord(Business $business): BillingRecord
    {
        $plan = Plan::create([
            'key' => 'statement_receipt_probe',
            'name' => 'Statement Receipt Probe',
            'model' => 'per_referral',
            'per_referral_cents' => 200,
            'monthly_cap_cents' => 400,
            'active' => true,
        ]);
        $business->forceFill([
            'free_visits_remaining' => 1,
            'current_plan_id' => $plan->id,
        ])->save();

        $this->card($business);
        $membership = $this->membership($business);

        $this->visit($business, $membership, '2026-09-03 12:00:00');
        $this->visit($business, $membership, '2026-09-10 12:00:00');
        $this->visit($business, $membership, '2026-09-17 12:00:00');
        $this->visit($business, $membership, '2026-09-24 12:00:00');

        $record = app(MonthlyCharger::class)->charge($business->fresh());

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame(400, $record->amount_cents);

        return $record;
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    public function test_bs1_header_names_the_period_states_paid_and_scales_the_charge(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $record = $this->septemberRecord($business);

        $response = $this->actingAs($owner)->get(route('settings.billing.statement', $record));
        $response->assertOk();
        $content = $response->getContent();

        // The H1 is the period statement title, rendered from
        // the record's own window exactly as the page renders
        // the period today; "Statement" alone is no longer an H1.
        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>Statement for Sep 3 to Sep 24, 2026<\/h1>/',
            $content,
        );
        // The Paid pill states the fact the controller already
        // guarantees (a succeeded record), in pill chrome.
        $response->assertSee('pill pill-success', false);
        $this->assertMatchesRegularExpression('/<span class="pill pill-success">Paid<\/span>/', $content);
        // The charge at tile-value scale equals the record
        // amount to the cent.
        $this->assertMatchesRegularExpression(
            '/<p class="tile-value[^"]*">'.preg_quote($this->money($record->amount_cents), '/').'<\/p>/',
            $content,
        );
    }

    public function test_bs1_charge_matches_record_amount_for_a_past_due_recovered_business(): void
    {
        // The past-due-recovered shape: the business went
        // past_due, the arrears charge then landed through the
        // charger, and billing recovered to active. Its
        // statement charge is the record amount to the cent.
        $business = World::business(['billing_status' => 'past_due', 'free_visits_remaining' => 0]);
        $this->card($business);
        $membership = $this->membership($business);
        $this->visit($business, $membership);
        $this->visit($business, $membership);
        $this->visit($business, $membership);

        $record = app(MonthlyCharger::class)->charge($business->fresh());
        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $business->forceFill(['billing_status' => 'active'])->save();

        $response = $this->actingAs(World::owner($business))
            ->get(route('settings.billing.statement', $record));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<p class="tile-value[^"]*">'.preg_quote($this->money($record->amount_cents), '/').'<\/p>/',
            $content,
        );
        // Tile, visits row, and total row all carry the figure.
        $this->assertGreaterThanOrEqual(3, substr_count($content, $this->money($record->amount_cents)));
    }

    public function test_bs2_receipt_rows_explain_coverage_show_zero_amounts_and_sum_to_the_total(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $record = $this->septemberRecord($business);

        $response = $this->actingAs($owner)->get(route('settings.billing.statement', $record));
        $response->assertOk();
        $content = $response->getContent();

        // Fixed order: visits charged, free visits, over cap.
        $chargedAt = strpos($content, 'Visits charged: 2');
        $freeAt = strpos($content, 'Free visits used: 1');
        $overCapAt = strpos($content, 'Visits past the monthly cap, recorded free: 1');
        $this->assertNotFalse($chargedAt);
        $this->assertNotFalse($freeAt);
        $this->assertNotFalse($overCapAt);
        $this->assertLessThan($freeAt, $chargedAt);
        $this->assertLessThan($overCapAt, $freeAt);

        // The ratified [BS-1] explanations, verbatim.
        $response->assertSee('Covered by the free visits included with your program.');
        $response->assertSee('Recorded, not charged. Your monthly cap was already reached.');

        // Coverage rows show $0.00 explicitly, never omitted:
        // exactly the free row and the over-cap row.
        $this->assertSame(2, substr_count($content, '$0.00'));
        // The rows visibly sum to the total: charged visits at
        // the plan rate ($4.00) are the only contribution, and
        // the total row repeats the record amount.
        $response->assertViewHas('chargedCents', 400);
        $this->assertMatchesRegularExpression(
            '/Total<\/dt>\s*<dd[^>]*>'.preg_quote($this->money($record->amount_cents), '/').'<\/dd>/',
            $content,
        );
    }

    public function test_bs2_mixed_record_rows_sum_visits_plus_texts_to_the_total(): void
    {
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 0]);
        $this->card($business);
        $membership = $this->membership($business);
        $this->visit($business, $membership);
        $this->visit($business, $membership);
        $this->attemptedRecipients($business, [200, 200]);

        $record = app(MonthlyCharger::class)->charge($business->fresh());
        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::KIND_PER_REFERRAL_BATCH, $record->kind);
        $this->assertSame(800, $record->amount_cents);

        $response = $this->actingAs(World::owner($business))
            ->get(route('settings.billing.statement', $record));
        $response->assertOk();
        $content = $response->getContent();

        $response->assertSee('Visits charged: 2');
        $response->assertSee('Text campaigns:');
        $response->assertSee('Texts sent in campaigns this charge covers, at 2 cents per text segment for each customer.');
        // Visits contribute $4.00 (2 x the $2 plan rate), texts
        // contribute their claimed $4.00, the total is the
        // record's $8.00.
        $response->assertViewHas('chargedCents', 400);
        $response->assertViewHas('textCampaignsCents', 400);
        $this->assertMatchesRegularExpression(
            '/Total<\/dt>\s*<dd[^>]*>'.preg_quote($this->money($record->amount_cents), '/').'<\/dd>/',
            $content,
        );
    }

    public function test_bs3_campaign_only_record_leads_with_texts_and_collapses_the_visit_rows(): void
    {
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 0]);
        $this->card($business);
        $this->attemptedRecipients($business, [200, 200, 200]);

        $record = app(MonthlyCharger::class)->charge($business->fresh());
        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::KIND_CAMPAIGN_METERING, $record->kind);
        $this->assertSame(600, $record->amount_cents);

        $response = $this->actingAs(World::owner($business))
            ->get(route('settings.billing.statement', $record));
        $response->assertOk();
        $content = $response->getContent();

        // No zero-count visit lines anywhere on the page.
        $response->assertDontSee('Visits charged');
        $response->assertDontSee('Free visits used');
        $response->assertDontSee('Visits past the monthly cap');
        // The texts row leads; the collapse line follows it.
        $textsAt = strpos($content, 'Text campaigns:');
        $collapseAt = strpos($content, 'No referral visits in this charge.');
        $this->assertNotFalse($textsAt);
        $this->assertNotFalse($collapseAt);
        $this->assertLessThan($collapseAt, $textsAt);
        $response->assertSee('Texts sent in campaigns this charge covers, at 2 cents per text segment for each customer.');
        // Charge and total both carry the record amount.
        $this->assertGreaterThanOrEqual(2, substr_count($content, $this->money($record->amount_cents)));
        $this->assertMatchesRegularExpression(
            '/Total<\/dt>\s*<dd[^>]*>'.preg_quote($this->money($record->amount_cents), '/').'<\/dd>/',
            $content,
        );
    }

    public function test_bs4_estimate_is_quarantined_and_no_breadcrumb_renders(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $record = $this->septemberRecord($business);

        $response = $this->actingAs($owner)->get(route('settings.billing.statement', $record));
        $response->assertOk();
        $content = $response->getContent();

        $response->assertDontSee('aria-label="Breadcrumb"', false);

        // Two panels: the receipt and the estimate's own panel.
        // The estimate's shipped label wording rides the second;
        // the charge and the total live only in the first.
        $panels = explode('<section class="panel', $content);
        $this->assertCount(3, $panels);
        $this->assertStringContainsString('Total', $panels[1]);
        $this->assertStringNotContainsString('What those visits were worth (estimate):', $panels[1]);
        $this->assertStringContainsString('What those visits were worth (estimate): $200.00', $panels[2]);
        $this->assertStringNotContainsString('tile-value', $panels[2]);

        // The shipped footer line and the ratified [BS-1]
        // questions line both render.
        $response->assertSee('Amounts are in US dollars. Charges are handled by Square.');
        $response->assertSee('Questions about this charge? See Billing for your card and plan.');
    }
}
