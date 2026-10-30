<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Reward;
use App\Services\Billing\RefundService;
use App\Services\Billing\SquareWebhookHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Money fix train, fix 2 (business-logic audit 3.2 and 3.3;
 * synthesis item 2): refunds can do what the refund page
 * promises.
 *
 * Before this train: no caller of RefundService::refund()
 * existed anywhere in app code; no 30-day window was enforced;
 * markRefundedVisits absorbed earliest-first across the
 * record, so a refund for one fraudulent visit clawed back the
 * batch's earliest visits, and cents that came from the text
 * leg absorbed visit cents the same way; a lost dispute
 * clawed back the whole record whatever the disputed amount.
 *
 * After: refund() enforces the stated 30-day window, accepts a
 * targeted billing event (the specific visit the refund is
 * about) and absorbs only that event; an untargeted refund
 * absorbs only the record's own visit leg, never its text
 * leg; a dispute loss claws back only the disputed amount;
 * and billing:refund is a working operator caller path.
 */
class RefundTargetingTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 6100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550987', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    /**
     * A succeeded record over the given visit count, with an
     * optional extra text leg folded into the record amount.
     *
     * @return array{0: BillingRecord, 1: list<BillingEvent>, 2: list<Reward>}
     */
    private function fundedBatch(Business $business, Membership $membership, int $visits, int $textLegCents = 0, ?string $recordAt = null): array
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200 * $visits + $textLegCents,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_target_'.uniqid(),
            'square_payment_id' => 'sq_payment_batch',
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        if ($recordAt !== null) {
            $record->forceFill(['created_at' => Carbon::parse($recordAt)])->save();
        }

        $events = [];
        $rewards = [];
        for ($i = 0; $i < $visits; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, ['state' => \App\Models\Referral::STATE_REDEEMED]);
            [$reward] = World::reward($membership, $referral, [
                'state' => Reward::STATE_PENDING_VESTING,
                'available_at' => now()->addDays(14),
                'notification_state' => 'queued',
            ]);
            $events[] = BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'billing_record_id' => $record->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
            $rewards[] = $reward;
        }

        return [$record, $events, $rewards];
    }

    public function test_targeted_refund_absorbs_only_the_named_visit(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events, $rewards] = $this->fundedBatch($business, $membership, 3);

        // The refund is about the third visit (the fraudulent
        // one), not the earliest.
        $refund = app(RefundService::class)->refund($record, 200, 'proven fraudulent referral', 'owner', $events[2]);

        $this->assertSame(BillingRefund::STATE_SUCCEEDED, $refund->state);
        $this->assertSame(200, $refund->amount_cents);
        $this->assertSame('billable', $events[0]->fresh()->coverage);
        $this->assertSame('billable', $events[1]->fresh()->coverage);
        $this->assertSame('refunded', $events[2]->fresh()->coverage);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[0]->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[1]->fresh()->state);
        $this->assertSame(Reward::STATE_DENIED, $rewards[2]->fresh()->state);
        $this->assertSame(BillingRecord::STATE_PARTIALLY_REFUNDED, $record->fresh()->state);
    }

    public function test_targeted_refund_defaults_its_amount_to_the_visit_and_rejects_mismatches(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events] = $this->fundedBatch($business, $membership, 2);
        $service = app(RefundService::class);

        try {
            $service->refund($record, 100, null, 'owner', $events[0]);
            $this->fail('A targeted refund for part of a visit must be refused.');
        } catch (InvalidArgumentException) {
        }

        $refund = $service->refund($record, null, null, 'owner', $events[1]);
        $this->assertSame(200, $refund->amount_cents);
        $this->assertSame('refunded', $events[1]->fresh()->coverage);

        try {
            $service->refund($record->fresh(), null, null, 'owner', $events[1]);
            $this->fail('A visit already refunded cannot be refunded again.');
        } catch (InvalidArgumentException) {
        }
    }

    public function test_targeted_refund_rejects_an_event_from_another_record(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$recordA] = $this->fundedBatch($business, $membership, 1);
        [, $eventsB] = $this->fundedBatch($business, $membership, 1);

        $this->expectException(InvalidArgumentException::class);
        app(RefundService::class)->refund($recordA, null, null, 'owner', $eventsB[0]);
    }

    public function test_refund_window_is_thirty_days_from_the_payment(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$inside] = $this->fundedBatch($business, $membership, 1, 0, '2026-03-01 12:00:00');
        [$outside] = $this->fundedBatch($business, $membership, 1, 0, '2026-03-01 12:00:00');
        $service = app(RefundService::class);

        Carbon::setTestNow('2026-03-31 12:00:00');
        $refund = $service->refund($inside);
        $this->assertSame(BillingRefund::STATE_SUCCEEDED, $refund->state);

        Carbon::setTestNow('2026-03-31 12:00:01');
        try {
            $service->refund($outside);
            $this->fail('A refund 30 days and a second after the payment must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('30-day', $e->getMessage());
        }
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $outside->fresh()->state);
        // The refused refund never reached Square: only the
        // in-window refund was executed.
        $this->assertCount(1, $this->gateway->refundCalls);
    }

    public function test_text_leg_cents_never_absorb_visits(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        // Record of 1,000: two visits (400) plus a 600 text leg.
        [$record, $events, $rewards] = $this->fundedBatch($business, $membership, 2, 600);

        // Refund the text leg, named as a text refund: no visit
        // was refunded, so no visit absorbs it and no reward is
        // clawed back.
        app(RefundService::class)->refund($record, 600, 'text leg correction', 'owner', null, 'texts');

        $this->assertSame('billable', $events[0]->fresh()->coverage);
        $this->assertSame('billable', $events[1]->fresh()->coverage);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[0]->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[1]->fresh()->state);
        $this->assertSame(BillingRecord::STATE_PARTIALLY_REFUNDED, $record->fresh()->state);
    }

    public function test_untargeted_refund_is_capped_at_the_visit_leg(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events] = $this->fundedBatch($business, $membership, 2, 600);

        // A full refund absorbs both visits (the whole visit
        // leg) and nothing beyond them; the record still ends
        // refunded because the full amount was returned.
        app(RefundService::class)->refund($record, 1000);

        $this->assertSame('refunded', $events[0]->fresh()->coverage);
        $this->assertSame('refunded', $events[1]->fresh()->coverage);
        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
    }

    public function test_dispute_loss_claws_back_only_the_disputed_amount(): void
    {
        $business = World::business([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
        ]);
        $membership = $this->membership($business);
        [$record, $events, $rewards] = $this->fundedBatch($business, $membership, 3);
        $record->forceFill(['state' => BillingRecord::STATE_DISPUTED])->save();

        app(SquareWebhookHandler::class)->handle([
            'type' => 'dispute.updated',
            'data' => ['object' => ['dispute' => [
                'id' => 'sq_dispute_partial',
                'status' => 'LOST',
                'amount_money' => ['amount' => 200, 'currency' => 'USD'],
                'disputed_payment' => ['payment_id' => 'sq_payment_batch'],
            ]]],
        ]);

        $this->assertSame('refunded', $events[0]->fresh()->coverage);
        $this->assertSame('billable', $events[1]->fresh()->coverage);
        $this->assertSame('billable', $events[2]->fresh()->coverage);
        $this->assertSame(Reward::STATE_DENIED, $rewards[0]->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[1]->fresh()->state);
        $this->assertSame(BillingRecord::STATE_PARTIALLY_REFUNDED, $record->fresh()->state);
    }

    public function test_dispute_loss_for_the_full_amount_still_refunds_the_record(): void
    {
        $business = World::business([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
        ]);
        $membership = $this->membership($business);
        [$record, $events] = $this->fundedBatch($business, $membership, 2);
        $record->forceFill(['state' => BillingRecord::STATE_DISPUTED])->save();

        app(SquareWebhookHandler::class)->handle([
            'type' => 'dispute.updated',
            'data' => ['object' => ['dispute' => [
                'id' => 'sq_dispute_full',
                'status' => 'LOST',
                'amount_money' => ['amount' => 400, 'currency' => 'USD'],
                'disputed_payment' => ['payment_id' => 'sq_payment_batch'],
            ]]],
        ]);

        $this->assertSame('refunded', $events[0]->fresh()->coverage);
        $this->assertSame('refunded', $events[1]->fresh()->coverage);
        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
    }

    public function test_the_console_command_is_a_working_refund_caller(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events] = $this->fundedBatch($business, $membership, 2);

        $this->artisan('billing:refund', [
            'record' => $record->id,
            '--event' => $events[1]->id,
            '--reason' => 'proven fraudulent referral',
        ])->assertExitCode(0);

        $this->assertSame('billable', $events[0]->fresh()->coverage);
        $this->assertSame('refunded', $events[1]->fresh()->coverage);
        $this->assertSame(1, BillingRefund::query()->where('state', BillingRefund::STATE_SUCCEEDED)->count());
    }

    public function test_external_webhook_refund_still_records_after_the_local_window(): void
    {
        // Square has already moved the money in its dashboard;
        // the local window gates our own caller path, never the
        // recording of a settled external refund.
        $business = World::business([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
        ]);
        $membership = $this->membership($business);
        [$record, $events] = $this->fundedBatch($business, $membership, 1, 0, '2026-01-01 12:00:00');

        Carbon::setTestNow('2026-03-15 12:00:00');
        app(SquareWebhookHandler::class)->handle([
            'type' => 'refund.updated',
            'data' => ['object' => ['refund' => [
                'id' => 'sq_refund_late',
                'payment_id' => 'sq_payment_batch',
                'status' => 'COMPLETED',
                'amount_money' => ['amount' => 200, 'currency' => 'USD'],
            ]]],
        ]);

        $this->assertSame(1, BillingRefund::query()->where('square_refund_id', 'sq_refund_late')->where('state', BillingRefund::STATE_SUCCEEDED)->count());
        $this->assertSame('refunded', $events[0]->fresh()->coverage);
    }
}
