<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Services\Billing\MonthlyCharger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-BILL-01: a charge record claims the coverage rows of the
 * months it bills (free_credit and over_cap events), not only
 * the billable ones, so the per-charge statement can account
 * for every visit in its own window. The claimed coverage
 * rows never enter the charge total; a definitive failure
 * releases all three classes; a later run re-claims them
 * under the new record.
 */
class MonthlyChargerCoverageClaimTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        config(['billing.charges_enabled' => true]);
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550987', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
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
        $event->forceFill(['created_at' => $at])->save();

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
            'idempotency_key' => 'card_'.$business->id.'_cov',
        ]);
    }

    private function cappedPlan(int $capCents): Plan
    {
        return Plan::create([
            'key' => 'cap_probe_'.$capCents,
            'name' => 'Cap Probe',
            'model' => 'per_referral',
            'per_referral_cents' => 200,
            'monthly_cap_cents' => $capCents,
            'active' => true,
        ]);
    }

    public function test_mixed_month_claims_free_and_billable_under_the_record(): void
    {
        $business = World::business(['free_visits_remaining' => 1]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $free = $this->event($business, $membership, '2026-09-03 12:00:00');
        $billedA = $this->event($business, $membership, '2026-09-10 12:00:00');
        $billedB = $this->event($business, $membership, '2026-09-17 12:00:00');

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        // The free visit is claimed for the statement but never
        // enters the charge: the total is the two billed visits.
        $this->assertSame(400, $record->amount_cents);
        $this->assertSame(400, $this->gateway->chargeCalls[0]['amount_cents']);
        $this->assertCount(2, $this->gateway->chargeCalls[0]['line_items']);
        $this->assertSame($record->id, $free->fresh()->billing_record_id);
        $this->assertSame($record->id, $billedA->fresh()->billing_record_id);
        $this->assertSame($record->id, $billedB->fresh()->billing_record_id);
        $this->assertSame('free_credit', $free->fresh()->coverage);
        // The record's period spans the free visit too: the
        // statement window is the month the owner lived.
        $this->assertSame('2026-09-03', $record->period_start->format('Y-m-d'));
        $this->assertSame('2026-09-17', $record->period_end->format('Y-m-d'));
    }

    public function test_free_only_month_produces_no_record_and_claims_nothing(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $events = [];
        for ($i = 0; $i < 3; $i++) {
            $events[] = $this->event($business, $membership, '2026-09-0'.(3 + $i).' 12:00:00');
        }

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNull($record);
        $this->assertSame(0, BillingRecord::count());
        $this->assertCount(0, $this->gateway->chargeCalls);
        foreach ($events as $event) {
            $fresh = $event->fresh();
            $this->assertSame('free_credit', $fresh->coverage);
            $this->assertNull($fresh->billing_record_id);
        }
        // The credit was still consumed by settlement.
        $this->assertSame(2, $business->fresh()->free_visits_remaining);
    }

    public function test_cap_crossing_month_claims_the_over_cap_events(): void
    {
        $plan = $this->cappedPlan(400);
        $business = World::business(['free_visits_remaining' => 0, 'current_plan_id' => $plan->id]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $events = [];
        for ($i = 0; $i < 4; $i++) {
            $events[] = $this->event($business, $membership, '2026-09-'.(3 + $i * 5).' 12:00:00');
        }

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(400, $record->amount_cents);
        $this->assertSame(
            ['billable', 'billable', 'over_cap', 'over_cap'],
            array_map(fn (BillingEvent $e) => $e->fresh()->coverage, $events),
        );
        foreach ($events as $event) {
            $this->assertSame($record->id, $event->fresh()->billing_record_id);
        }
    }

    public function test_failed_charge_releases_all_three_coverage_classes(): void
    {
        $plan = $this->cappedPlan(400);
        $business = World::business(['free_visits_remaining' => 1, 'current_plan_id' => $plan->id]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $events = [];
        for ($i = 0; $i < 4; $i++) {
            $events[] = $this->event($business, $membership, '2026-09-'.(3 + $i * 5).' 12:00:00');
        }

        $this->gateway->chargeFailureCode = 'CARD_DECLINED';
        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(BillingRecord::STATE_FAILED, $record->state);
        $this->assertSame(
            ['free_credit', 'billable', 'billable', 'over_cap'],
            array_map(fn (BillingEvent $e) => $e->fresh()->coverage, $events),
        );
        foreach ($events as $event) {
            $this->assertNull(
                $event->fresh()->billing_record_id,
                'A failed charge must release every claimed class, coverage rows included.',
            );
        }
    }

    public function test_recharge_after_failure_claims_coverage_under_the_new_record(): void
    {
        $plan = $this->cappedPlan(400);
        $business = World::business(['free_visits_remaining' => 1, 'current_plan_id' => $plan->id]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $events = [];
        for ($i = 0; $i < 4; $i++) {
            $events[] = $this->event($business, $membership, '2026-09-'.(3 + $i * 5).' 12:00:00');
        }

        $charger = app(MonthlyCharger::class);
        $this->gateway->chargeFailureCode = 'CARD_DECLINED';
        $failed = $charger->charge($business);
        $this->assertSame(BillingRecord::STATE_FAILED, $failed->state);

        $this->gateway->chargeFailureCode = null;
        $record = $charger->charge($business);

        $this->assertNotNull($record);
        $this->assertNotSame($failed->id, $record->id);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame(400, $record->amount_cents);
        foreach ($events as $event) {
            $this->assertSame($record->id, $event->fresh()->billing_record_id);
        }
    }

    public function test_full_refund_marks_only_the_billed_visits_refunded(): void
    {
        $business = World::business(['free_visits_remaining' => 1]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $free = $this->event($business, $membership, '2026-09-03 12:00:00');
        $billedA = $this->event($business, $membership, '2026-09-10 12:00:00');
        $billedB = $this->event($business, $membership, '2026-09-17 12:00:00');

        $record = app(MonthlyCharger::class)->charge($business);
        $this->assertSame(400, $record->amount_cents);

        app(\App\Services\Billing\RefundService::class)->refund($record);

        // The refund walks the visits the owner actually paid
        // for; the free visit was never charged, so it is
        // never "refunded" and its reward is never clawed back.
        $this->assertSame('refunded', $billedA->fresh()->coverage);
        $this->assertSame('refunded', $billedB->fresh()->coverage);
        $this->assertSame('free_credit', $free->fresh()->coverage);
    }

    public function test_free_rows_from_an_earlier_free_only_month_join_the_first_later_batch(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        // August is a free-only month: it gets no charge of its
        // own, so its coverage row rides the first later batch
        // (the verdict's scope: every settled coverage row up
        // to the end of the batch's newest month is claimed).
        $august = $this->event($business, $membership, '2026-08-05 12:00:00');
        // September spends the rest of the credit, then bills.
        $septemberFree = [];
        for ($i = 0; $i < 4; $i++) {
            $septemberFree[] = $this->event($business, $membership, '2026-09-0'.(2 + $i).' 12:00:00');
        }
        $billed = $this->event($business, $membership, '2026-09-20 12:00:00');

        $record = app(MonthlyCharger::class)->charge($business);

        $this->assertNotNull($record);
        $this->assertSame(200, $record->amount_cents);
        foreach ($septemberFree as $event) {
            $this->assertSame($record->id, $event->fresh()->billing_record_id);
        }
        $this->assertSame($record->id, $billed->fresh()->billing_record_id);
        $this->assertSame($record->id, $august->fresh()->billing_record_id);
        $this->assertSame('2026-08-05', $record->period_start->format('Y-m-d'));
    }
}
