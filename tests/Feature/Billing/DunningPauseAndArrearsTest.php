<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
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
 * Money fix train, fix 1 (business-logic audit 1.3, 3.1, 4.3;
 * synthesis items 1 and 7): the dunning pause the owner emails
 * promise actually happens, and delinquent arrears settle
 * month by month as separate records.
 *
 * The pause: DUN-04 and DUN-05 promise new signups pause on the
 * grace end date (the past_due episode start plus
 * config billing.dunning_grace_days). Before this train,
 * Business::flowIsActive() admitted past_due forever, so the
 * dated promise never happened and accrual was unbounded.
 *
 * The arrears shape: the cap binds per month of visits, so a
 * charge must never sweep several months of visits into one
 * payment. MonthlyCharger charges one month per record (the
 * oldest unclaimed month first); chargeAllMonths() drains
 * several arrears months in one run as separate records.
 *
 * The This month seam (audit 4.3): the billing page block
 * counted the current month (forMonth) beside an all-months
 * accrued figure with no label telling them apart. The block
 * now scopes its built-up figure to the current month and
 * names any earlier-months remainder on its own line.
 */
class DunningPauseAndArrearsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 3000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        config(['billing.charges_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function cardOnFile(Business $business): void
    {
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_test',
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_test',
        ]);
    }

    private function event(Business $business, Membership $membership, string $at, int $amountCents = 200): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
        ]);
        $event->forceFill(['created_at' => Carbon::parse($at)])->save();

        return $event;
    }

    /** Open a past_due episode at the given instant, the way the webhook handler does. */
    private function openPastDueEpisode(Business $business, string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at));
        AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_pay_failed'], $business, null);
        Carbon::setTestNow(null);
    }

    public function test_past_due_flow_stays_live_through_the_grace_window(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->openPastDueEpisode($business, '2026-03-10 12:00:00');

        Carbon::setTestNow('2026-03-16 12:00:00');
        $this->assertTrue($business->fresh()->flowIsActive());

        Carbon::setTestNow('2026-03-17 11:59:59');
        $this->assertTrue($business->fresh()->flowIsActive());
    }

    public function test_past_due_flow_pauses_when_the_promised_grace_date_passes(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->openPastDueEpisode($business, '2026-03-10 12:00:00');

        // The promised pause date is Mar 17 (episode + 7 days):
        // at that instant the flow is paused, and stays paused.
        Carbon::setTestNow('2026-03-17 12:00:00');
        $this->assertFalse($business->fresh()->flowIsActive());

        Carbon::setTestNow('2026-04-01 12:00:00');
        $this->assertFalse($business->fresh()->flowIsActive());
    }

    public function test_the_pause_reads_the_latest_episode_not_an_old_one(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->openPastDueEpisode($business, '2026-01-10 12:00:00');
        $this->openPastDueEpisode($business, '2026-03-10 12:00:00');

        Carbon::setTestNow('2026-03-15 12:00:00');
        $this->assertTrue($business->fresh()->flowIsActive());

        Carbon::setTestNow('2026-03-18 12:00:00');
        $this->assertFalse($business->fresh()->flowIsActive());
    }

    public function test_active_business_is_unaffected_and_undatable_past_due_pauses(): void
    {
        $active = World::business(['billing_status' => 'active']);
        Carbon::setTestNow('2026-03-20 12:00:00');
        $this->assertTrue($active->fresh()->flowIsActive());

        // FLOW-STATE TRUTH train (2026-10-30) supersedes the
        // old undated-stays-live rule: a past_due row with NO
        // episode evidence at all (no audit row, no failed
        // record) cannot prove any grace remains, so it fails
        // closed — paused, never an open-ended "keeps working".
        // (Two-key Finding 1 fix, 2026-10-30: an episode missing
        // its audit row fails closed even when failed records
        // exist — they are not episode-bound; see
        // FlowStateTruthTest.)
        $undated = World::business(['billing_status' => 'past_due']);
        $this->assertTrue($undated->fresh()->dunningPausedAt());
        $this->assertFalse($undated->fresh()->flowIsActive());

        // A business that is not active never flows, past_due
        // bookkeeping notwithstanding.
        $closed = World::business(['billing_status' => 'past_due', 'status' => 'closed']);
        $this->openPastDueEpisode($closed, '2026-03-19 12:00:00');
        $this->assertFalse($closed->fresh()->flowIsActive());
    }

    public function test_dunning_grace_end_matches_the_pause_boundary(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->openPastDueEpisode($business, '2026-03-10 12:00:00');

        $graceEnd = $business->fresh()->dunningGraceEndsAt();
        $this->assertNotNull($graceEnd);
        $this->assertSame('2026-03-17 12:00:00', $graceEnd->format('Y-m-d H:i:s'));
        $this->assertFalse($business->fresh()->dunningPausedAt(\Illuminate\Support\Carbon::parse('2026-03-17 11:59:59')));
        $this->assertTrue($business->fresh()->dunningPausedAt(\Illuminate\Support\Carbon::parse('2026-03-17 12:00:00')));
    }

    public function test_charge_settles_one_month_per_record_oldest_first(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $january = [$this->event($business, $membership, '2026-01-10 12:00:00'), $this->event($business, $membership, '2026-01-20 12:00:00')];
        $february = [
            $this->event($business, $membership, '2026-02-05 12:00:00'),
            $this->event($business, $membership, '2026-02-12 12:00:00'),
            $this->event($business, $membership, '2026-02-19 12:00:00'),
        ];

        $charger = app(MonthlyCharger::class);

        $first = $charger->charge($business);
        $this->assertNotNull($first);
        $this->assertSame(400, $first->amount_cents);
        $this->assertSame('2026-01-10', $first->period_start->format('Y-m-d'));
        $this->assertSame('2026-01-20', $first->period_end->format('Y-m-d'));
        foreach ($january as $event) {
            $this->assertSame($first->id, $event->fresh()->billing_record_id);
        }
        foreach ($february as $event) {
            $this->assertNull($event->fresh()->billing_record_id);
        }

        $second = $charger->charge($business->fresh());
        $this->assertNotNull($second);
        $this->assertSame(600, $second->amount_cents);
        foreach ($february as $event) {
            $this->assertSame($second->id, $event->fresh()->billing_record_id);
        }

        // Two Square charges, one per month of visits; never one
        // combined 1,000-cent charge.
        $this->assertCount(2, $this->gateway->chargeCalls);
        $this->assertSame([400, 600], array_column($this->gateway->chargeCalls, 'amount_cents'));
        $this->assertNull($charger->charge($business->fresh()));
    }

    public function test_charge_all_months_drains_arrears_as_separate_capped_records(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        // Two full months at the cap (55 visits each month:
        // 49 billed at 9,800, 6 over cap) plus a small March.
        foreach (['2026-01-10', '2026-02-10'] as $day) {
            for ($i = 0; $i < 55; $i++) {
                $this->event($business, $membership, $day.' 12:00:00');
            }
        }
        $this->event($business, $membership, '2026-03-10 12:00:00');

        $records = app(MonthlyCharger::class)->chargeAllMonths($business);

        $this->assertCount(3, $records);
        $this->assertSame([9800, 9800, 200], array_map(fn (BillingRecord $r) => $r->amount_cents, $records));
        foreach ($records as $record) {
            $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
            $this->assertLessThanOrEqual(9900, $record->amount_cents);
        }
        $this->assertCount(3, $this->gateway->chargeCalls);
        $this->assertSame(0, BillingEvent::query()->whereNull('billing_record_id')->where('coverage', 'billable')->count());
    }

    public function test_text_leg_rides_its_own_months_record(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $januaryVisit = $this->event($business, $membership, '2026-01-10 12:00:00');
        $februaryVisit = $this->event($business, $membership, '2026-02-10 12:00:00');
        $januaryText = $this->smsRow($business, '2026-01-15 12:00:00', 100);
        $februaryText = $this->smsRow($business, '2026-02-15 12:00:00', 300);

        $records = app(MonthlyCharger::class)->chargeAllMonths($business);

        $this->assertCount(2, $records);
        $this->assertSame(300, $records[0]->amount_cents);
        $this->assertSame(500, $records[1]->amount_cents);
        $this->assertSame($records[0]->id, $januaryVisit->fresh()->billing_record_id);
        $this->assertSame($records[0]->id, $januaryText->fresh()->billing_record_id);
        $this->assertSame($records[1]->id, $februaryVisit->fresh()->billing_record_id);
        $this->assertSame($records[1]->id, $februaryText->fresh()->billing_record_id);
    }

    public function test_a_failed_month_stops_the_drain_and_releases_its_events(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $january = $this->event($business, $membership, '2026-01-10 12:00:00');
        $february = $this->event($business, $membership, '2026-02-10 12:00:00');

        $this->gateway->chargeFailureCode = 'CARD_DECLINED';
        $records = app(MonthlyCharger::class)->chargeAllMonths($business);

        // One attempt, one failed record; February is not
        // attempted against the same declined card in this run.
        $this->assertCount(1, $records);
        $this->assertSame(BillingRecord::STATE_FAILED, $records[0]->state);
        $this->assertNull($january->fresh()->billing_record_id);
        $this->assertNull($february->fresh()->billing_record_id);
        $this->assertCount(1, $this->gateway->chargeCalls);
    }

    public function test_this_month_block_scopes_its_built_up_figure_to_this_month(): void
    {
        Carbon::setTestNow('2026-03-15 12:00:00');
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 0]);
        // Train A2: the forecast line below names a charge
        // date, which is true only with a card on file.
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_dp_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_dp_'.$business->id,
        ]);
        $membership = $this->membership($business);

        // Arrears from February (still unclaimed) beside March.
        $this->event($business, $membership, '2026-02-10 12:00:00');
        $this->event($business, $membership, '2026-02-11 12:00:00');
        $this->event($business, $membership, '2026-03-10 12:00:00');

        $response = $this->actingAs(World::owner($business))->get(route('settings.billing'));

        $response->assertOk();
        // Merge reconciliation (lanes 6 x 3, 2026-10-26): the R2
        // redesign renders this block as tiles (label + value),
        // and the old built-up sentence is gone by design; the
        // figures and their scoping are what this test guards.
        $response->assertSee('Visits counted');
        $response->assertSee('Visit fees so far');
        $response->assertSee('$2.00 of $99 monthly cap');
        $response->assertDontSee('Built up so far:');
        // The February remainder is named on its own line, and
        // the forecast still quotes the full amount that will
        // be charged (as separate monthly charges).
        $response->assertSee('Earlier months still to charge: $4.00');
        // Train 3.5 (merged after this pin was written) names the
        // charge on its business-local date: the April 1 03:00 UTC
        // charge is March 31 in this business's America/New_York
        // zone, so the forecast says March 31.
        $response->assertSee('Counted so far: 3 visits through March 31. Expected charge on March 31: $6.00.');
    }

    private function smsRow(Business $business, string $at, int $amountCents): SmsCampaignRecipient
    {
        $campaign = SmsCampaign::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'body' => 'Spring sale is on',
                'recipient_count' => 1,
                'segment_count' => 1,
                'metered_cents' => 0,
                'status' => SmsCampaign::STATUS_SENT,
                'created_by' => World::owner($business)->id,
                'sent_at' => now(),
            ],
        );

        $customer = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Cam', 'Paign');
        [$membership] = World::membership($business, $customer);

        $message = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'purpose' => 'campaign',
            'messaging_service' => 'marketing',
            'template_key' => 'campaign',
            'rendered_body' => 'Spring sale is on',
            'twilio_message_sid' => 'SM_FIX1_'.$customer->id,
            'delivery_state' => 'sent',
            'consent_basis' => 'customer_checkbox',
            'opt_out_state_at_send' => false,
        ]);

        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $membership->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'twilio_message_sid' => 'SM_FIX1_'.$customer->id,
            'sms_message_id' => $message->id,
            'delivery_state' => 'sent',
            'segments' => 1,
            'amount_cents' => $amountCents,
        ]);
        $row->forceFill(['created_at' => Carbon::parse($at)])->save();

        return $row;
    }
}
