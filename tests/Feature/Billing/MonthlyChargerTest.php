<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\BusinessAlert;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Services\Billing\ChargeResult;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\PendingRecordSweeper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Fakes\ThrowingSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The monthly charge assembly (spec Sections 9.5, 9.3, 9.9;
 * coverage map row 4; BILL-04, BILL-05, BILL-13, BILL-14,
 * BILL-17, BILL-40, BILL-42): charge total is the sum of the
 * month's billable fees, a zero-due business produces no charge,
 * a batch re-run never double-charges, failures and timeouts
 * resolve truthfully, and record-only mode simulates without
 * claiming or calling.
 */
class MonthlyChargerTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        config(['billing.charges_enabled' => true]);
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function event(Business $business, Membership $membership, int $amountCents = 200, ?Carbon $at = null): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
        ]);

        if ($at !== null) {
            $event->forceFill(['created_at' => $at])->save();
        }

        return $event;
    }

    private function cardOnFile(Business $business): PaymentMethod
    {
        return PaymentMethod::create([
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

    public function test_charges_the_billable_events_once_with_one_line_item_each(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        for ($i = 0; $i < 3; $i++) {
            $this->event($business, $membership);
        }

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame(600, $record->amount_cents);
        $this->assertSame('sq_payment_fake_1', $record->square_payment_id);
        $this->assertSame('sq_order_fake_1', $record->square_order_id);
        $this->assertMatchesRegularExpression('/^perref_'.$business->id.'_\d{4}-\d{2}-\d{2}_1$/', $record->idempotency_key);
        $this->assertSame(3, BillingEvent::query()->where('billing_record_id', $record->id)->count());
        $this->assertCount(1, $this->gateway->chargeCalls);
        $this->assertCount(3, $this->gateway->chargeCalls[0]['line_items']);
        $this->assertSame(600, $this->gateway->chargeCalls[0]['amount_cents']);
    }

    public function test_zero_due_business_produces_no_charge_and_no_record(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        for ($i = 0; $i < 3; $i++) {
            $this->event($business, $membership);
        }

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNull($record);
        $this->assertSame(0, BillingRecord::count());
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    public function test_sub_minimum_total_carries_forward_until_it_reaches_a_dollar(): void
    {
        $plan = Plan::create([
            'key' => 'fifty_cent_probe',
            'name' => 'Fifty Cent Probe',
            'model' => 'per_referral',
            'per_referral_cents' => 50,
            'monthly_cap_cents' => 9900,
            'active' => true,
        ]);
        $business = World::business(['free_visits_remaining' => 0, 'current_plan_id' => $plan->id]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $first = $this->event($business, $membership, 50);
        $charger = app(MonthlyCharger::class);

        // 50 cents is below Square's $1 minimum: no charge, the
        // event stays unclaimed (BILL-14).
        $this->assertNull($charger->charge($business));
        $this->assertNull($first->fresh()->billing_record_id);
        $this->assertCount(0, $this->gateway->chargeCalls);

        $this->event($business, $membership, 50);
        $record = $charger->charge($business->fresh());

        $this->assertNotNull($record);
        $this->assertSame(100, $record->amount_cents);
        $this->assertSame(2, BillingEvent::query()->where('billing_record_id', $record->id)->count());
    }

    public function test_rerun_never_double_charges(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $this->event($business, $membership);

        $charger = app(MonthlyCharger::class);
        $first = $charger->charge($business);
        $second = $charger->charge($business->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, BillingRecord::count());
        $this->assertCount(1, $this->gateway->chargeCalls);
    }

    public function test_failed_charge_releases_events_and_the_next_run_rebuilds_under_a_new_key(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership);

        $this->gateway->chargeFailureCode = 'CARD_DECLINED';
        $charger = app(MonthlyCharger::class);
        $failed = $charger->charge($business);

        $this->assertSame(BillingRecord::STATE_FAILED, $failed->state);
        $this->assertSame('CARD_DECLINED', $failed->error_code);
        $this->assertNull($event->fresh()->billing_record_id);

        $this->gateway->chargeFailureCode = null;
        $rebuilt = $charger->charge($business->fresh());

        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $rebuilt->state);
        $this->assertNotSame($failed->idempotency_key, $rebuilt->idempotency_key);
        $this->assertStringEndsWith('_2', $rebuilt->idempotency_key);
        $this->assertSame($rebuilt->id, $event->fresh()->billing_record_id);
        $this->assertCount(2, $this->gateway->chargeCalls);
    }

    public function test_unknown_outcome_leaves_the_record_pending_and_the_sweeper_adopts_squares_charge(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership);

        $this->app->instance(SquarePaymentsGateway::class, new ThrowingSquarePaymentsGateway);
        $record = app(MonthlyCharger::class)->charge($business);

        // BILL-05/EC-33: the UI of record is the truth, pending,
        // with the events claimed under the same key.
        $this->assertSame(BillingRecord::STATE_PENDING, $record->state);
        $this->assertSame($record->id, $event->fresh()->billing_record_id);

        $this->gateway->resolvedCharges[$record->idempotency_key] = ChargeResult::succeeded('sq_payment_adopted', 'sq_order_adopted');
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);

        $summary = app(PendingRecordSweeper::class)->sweep(0);

        $this->assertSame(1, $summary['adopted']);
        $record->refresh();
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame('sq_payment_adopted', $record->square_payment_id);
        $this->assertSame($record->id, $event->fresh()->billing_record_id);
    }

    public function test_sweeper_supersedes_when_square_has_nothing_and_the_events_charge_fresh(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership);

        $this->app->instance(SquarePaymentsGateway::class, new ThrowingSquarePaymentsGateway);
        $record = app(MonthlyCharger::class)->charge($business);
        $this->assertSame(BillingRecord::STATE_PENDING, $record->state);

        // The fake gateway holds nothing for the key: Square
        // never processed it, so the record is superseded and
        // the events released.
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $summary = app(PendingRecordSweeper::class)->sweep(0);

        $this->assertSame(1, $summary['superseded']);
        $this->assertSame(BillingRecord::STATE_SUPERSEDED, $record->fresh()->state);
        $this->assertNull($event->fresh()->billing_record_id);

        $fresh = app(MonthlyCharger::class)->charge($business->fresh());
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $fresh->state);
        $this->assertNotSame($record->idempotency_key, $fresh->idempotency_key);
    }

    public function test_record_only_mode_simulates_without_claiming_or_calling(): void
    {
        config(['billing.charges_enabled' => false]);
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership);

        $record = app(MonthlyCharger::class)->charge($business);

        // BILL-17: dry runs are simulated, never pending, claim
        // nothing, and never reach the provider boundary.
        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::STATE_SIMULATED, $record->state);
        $this->assertSame(200, $record->amount_cents);
        $this->assertNull($event->fresh()->billing_record_id);
        $this->assertCount(0, $this->gateway->chargeCalls);

        $summary = app(PendingRecordSweeper::class)->sweep(0);
        $this->assertSame(BillingRecord::STATE_SIMULATED, $record->fresh()->state);
        $this->assertSame(0, $summary['adopted'] + $summary['failed'] + $summary['superseded']);
    }

    public function test_business_without_a_card_is_skipped_with_an_owner_alert_and_accrues(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $event = $this->event($business, $membership);

        $record = app(MonthlyCharger::class)->charge($business);

        // BILL-42/Section 9.9: skipped, alerted, accrued in the
        // open, never written off.
        $this->assertNull($record);
        $this->assertSame(0, BillingRecord::count());
        $this->assertNull($event->fresh()->billing_record_id);
        $this->assertSame('billable', $event->fresh()->coverage);
        $this->assertTrue(
            BusinessAlert::query()
                ->where('business_id', $business->id)
                ->where('kind', 'billing_card_missing')
                ->exists(),
        );

        // The first batch after a valid card arrives collects
        // the accrued amount.
        $this->cardOnFile($business);
        $charged = app(MonthlyCharger::class)->charge($business->fresh());
        $this->assertSame(200, $charged->amount_cents);
    }

    public function test_free_and_over_cap_visits_are_never_in_the_charge(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        for ($i = 0; $i < 60; $i++) {
            $this->event($business, $membership);
        }

        $record = app(MonthlyCharger::class)->charge($business);

        // BILL-40 at the charge: 5 free, 49 billed ($98), 6 over
        // cap, exactly 49 line items. FE-BILL-01: the record
        // claims all 60 events (the coverage rows carry its
        // statement counts) while the charge itself still
        // contains the 49 billed visits only.
        $this->assertSame(9800, $record->amount_cents);
        $this->assertCount(49, $this->gateway->chargeCalls[0]['line_items']);
        $this->assertSame(49, BillingEvent::query()->where('billing_record_id', $record->id)->where('coverage', 'billable')->count());
        $this->assertSame(60, BillingEvent::query()->where('billing_record_id', $record->id)->count());
        $this->assertSame(5, BillingEvent::query()->where('coverage', 'free_credit')->count());
        $this->assertSame(6, BillingEvent::query()->where('coverage', 'over_cap')->count());
    }

    public function test_carried_months_charge_as_separate_records_each_under_its_own_cap(): void
    {
        // Superseded pin (money fix train, audit 1.3): this test
        // used to assert the two months combined into ONE
        // 19,600-cent record. That combined shape is the defect:
        // the cap binds per month of visits, so arrears settle
        // month by month as separate records.
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        for ($i = 0; $i < 55; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-01-10 12:00:00'));
        }
        for ($i = 0; $i < 55; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-02-10 12:00:00'));
        }

        $charger = app(MonthlyCharger::class);
        $january = $charger->charge($business);

        $this->assertSame(9800, $january->amount_cents);
        // FE-BILL-01: January's 49 billed visits claimed for the
        // charge, plus January's 6 over-cap rows for its
        // statement. February's rows are untouched.
        $this->assertSame(49, BillingEvent::query()->where('billing_record_id', $january->id)->where('coverage', 'billable')->count());
        $this->assertSame(55, BillingEvent::query()->where('billing_record_id', $january->id)->count());
        $this->assertSame(55, BillingEvent::query()->whereNull('billing_record_id')->count());

        $february = $charger->charge($business->fresh());

        $this->assertSame(9800, $february->amount_cents);
        $this->assertSame(49, BillingEvent::query()->where('billing_record_id', $february->id)->where('coverage', 'billable')->count());
        $this->assertSame(55, BillingEvent::query()->where('billing_record_id', $february->id)->count());
        $this->assertCount(2, $this->gateway->chargeCalls);
    }
}
