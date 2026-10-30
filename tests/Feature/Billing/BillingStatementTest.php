<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
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
 * Charge history completion and the per-charge statement
 * (billing UI spec Section 2, Blocks E and F; build unit
 * BU-6). History binds to the local billing_records ledger:
 * the engine creates no Square invoices, so the record and
 * its statement carry what an invoice would have carried.
 * Counts on the statement come from the record's own events
 * (the persisted coverage ledger), and the value line is the
 * period estimate, labeled as one, never sharing a line with
 * an exact charge figure.
 *
 * FE-BILL-01: the September fixture record is produced by
 * running MonthlyCharger over real settled visits, never by
 * hand-linking events to a record — the hand-linked fixture
 * is exactly what let the statement's free/over-cap lines
 * ship structurally zero (the charger claimed billable
 * events only, so no real record ever carried the coverage
 * rows the old fixture invented).
 */
class BillingStatementTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 9000;

    private int $keySequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        config(['billing.charges_enabled' => true]);
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550989', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function card(Business $business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_test_1',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_stmt',
        ]);
    }

    private function record(Business $business, ?PaymentMethod $card, array $overrides = []): BillingRecord
    {
        return BillingRecord::create(array_merge([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 400,
            'currency' => 'USD',
            'idempotency_key' => 'batch_stmt_'.(++$this->keySequence),
            'payment_method_id' => $card?->id,
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ], $overrides));
    }

    private function visit(Business $business, Membership $membership, string $at): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => 200,
        ]);
        $event->forceFill(['created_at' => $at])->save();

        return $event;
    }

    /**
     * A September record with the full coverage mix, produced
     * by the production path: one free-credit visit, two
     * charged visits, one over-cap visit recorded free, under
     * a plan whose $4.00 cap the fourth visit would cross.
     */
    private function septemberRecord(Business $business): BillingRecord
    {
        $plan = Plan::create([
            'key' => 'statement_cap_probe',
            'name' => 'Statement Cap Probe',
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

    public function test_history_rows_render_state_card_refunds_and_statement_link(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $record = $this->septemberRecord($business);

        BillingRefund::create([
            'billing_record_id' => $record->id,
            'square_refund_id' => 'rf_stmt_1',
            'amount_cents' => 100,
            'idempotency_key' => 'refund_stmt_1',
            'state' => BillingRefund::STATE_SUCCEEDED,
        ]);
        BillingRefund::create([
            'billing_record_id' => $record->id,
            'square_refund_id' => null,
            'amount_cents' => 200,
            'idempotency_key' => 'refund_stmt_2',
            'state' => BillingRefund::STATE_PENDING,
        ]);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertSee('$4.00');
        $response->assertSee('Paid');
        $response->assertSee('VISA ending 5858');
        $response->assertSee('Sep 3 to Sep 24, 2026');
        $response->assertSee('Refund: $1.00');
        $response->assertSee('Refund: $2.00 · Processing');
        $response->assertSee('View statement');
        $response->assertSee(route('settings.billing.statement', $record));
    }

    public function test_history_maps_states_and_hides_superseded_records(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $card = $this->card($business);

        $this->record($business, $card, ['state' => BillingRecord::STATE_FAILED, 'amount_cents' => 600]);
        $this->record($business, $card, ['state' => BillingRecord::STATE_SIMULATED, 'amount_cents' => 800]);
        $this->record($business, $card, ['state' => BillingRecord::STATE_SUPERSEDED, 'amount_cents' => 700]);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertSee('Did not go through');
        $response->assertSee('Record only. No charge was made.');
        $response->assertDontSee('$7.00');
    }

    public function test_statement_reconciles_counts_and_labels_the_estimate(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $record = $this->septemberRecord($business);

        $response = $this->actingAs($owner)->get(route('settings.billing.statement', $record));

        $response->assertOk();
        // The charge itself, exact: the BS-1 tile and the BS-2
        // total row both carry the record amount ($4.00).
        $response->assertSee('tile-value', false);
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), '$4.00'));
        // Coverage counts from the record's own ledger: the
        // charger claimed all three classes under the record.
        $response->assertSee('Visits charged: 2');
        $response->assertSee('Free visits used: 1');
        $response->assertSee('Visits past the monthly cap, recorded free: 1');
        // The period estimate: 4 visits at the $50 average ticket.
        $response->assertSee('What those visits were worth (estimate): $200.00');
        $response->assertSee('Amounts are in US dollars. Charges are handled by Square.');
        $response->assertSee('Sep 3 to Sep 24, 2026');
    }

    public function test_statement_is_owner_scoped_and_succeeded_only(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $record = $this->septemberRecord($business);

        $other = World::business(['billing_status' => 'active']);
        $otherOwner = World::owner($other);

        // Another owner cannot read this business's statement.
        $this->actingAs($otherOwner)
            ->get(route('settings.billing.statement', $record))
            ->assertNotFound();

        // A record that never succeeded has no statement.
        $failed = $this->record($business, null, ['state' => BillingRecord::STATE_FAILED]);
        $owner = World::owner($business);
        $this->actingAs($owner)
            ->get(route('settings.billing.statement', $failed))
            ->assertNotFound();
    }
}
