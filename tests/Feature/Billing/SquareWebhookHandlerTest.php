<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Billing\SquareWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Webhook-driven billing state transitions (spec Sections 9.4,
 * 9.6, 9.8; billing UI spec Section 4, the B3 contract): a failed
 * charge sets past_due, a successful charge restores active,
 * externally created refunds land as billing_refunds rows with
 * the Section 9.8 clawback, and disputes move the record through
 * disputed to their resolution. Handlers are state-monotonic:
 * no event moves a record backwards.
 */
class SquareWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
    }

    private function handler(): SquareWebhookHandler
    {
        return app(SquareWebhookHandler::class);
    }

    private function business(array $overrides = []): Business
    {
        return World::business(array_merge([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
        ], $overrides));
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550987', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    /**
     * A record in the given state; with visits, one billed event
     * (and a reward in the given state) per visit.
     *
     * @param  list<string>  $rewardStates
     * @return array{0: BillingRecord, 1: list<BillingEvent>, 2: list<Reward>}
     */
    private function record(Business $business, string $state, ?string $paymentId, array $rewardStates = []): array
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200 * max(1, count($rewardStates)),
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_2026-03-01_1',
            'square_payment_id' => $paymentId,
            'state' => $state,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        $events = [];
        $rewards = [];
        if ($rewardStates !== []) {
            $membership = $this->membership($business);
            foreach ($rewardStates as $rewardState) {
                $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
                [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED]);
                [$reward] = World::reward($membership, $referral, [
                    'state' => $rewardState,
                    'available_at' => $rewardState === Reward::STATE_PENDING_VESTING ? now()->addDays(14) : now()->subDay(),
                    'notification_state' => $rewardState === Reward::STATE_PENDING_VESTING ? 'queued' : 'sent',
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
        }

        return [$record, $events, $rewards];
    }

    /*
     * The builders below emit Square's real delivery envelope
     * (top-level event_id / merchant_id / location_id /
     * created_at; data{type, id, object}) — the shape proven
     * live on release 13. The handler reads type and
     * data.object only, but the suite speaks the real shape
     * everywhere so no fixture can drift from Square again.
     */

    /** @param array<string, mixed> $payment */
    private function paymentEvent(string $type, array $payment): array
    {
        return [
            'merchant_id' => 'MLTESTMERCHANT1',
            'location_id' => 'LTESTLOCATION1',
            'type' => $type,
            'event_id' => 'evt_'.($payment['id'] ?? 'payment'),
            'created_at' => '2026-10-24T20:06:51-04:00',
            'data' => ['type' => 'payment', 'id' => (string) ($payment['id'] ?? ''), 'object' => ['payment' => $payment]],
        ];
    }

    /** @param array<string, mixed> $refund */
    private function refundEvent(string $type, array $refund): array
    {
        return [
            'merchant_id' => 'MLTESTMERCHANT1',
            'location_id' => 'LTESTLOCATION1',
            'type' => $type,
            'event_id' => 'evt_'.($refund['id'] ?? 'refund'),
            'created_at' => '2026-10-24T20:06:51-04:00',
            'data' => ['type' => 'refund', 'id' => (string) ($refund['id'] ?? ''), 'object' => ['refund' => $refund]],
        ];
    }

    /** @param array<string, mixed> $dispute */
    private function disputeEvent(string $type, array $dispute): array
    {
        return [
            'merchant_id' => 'MLTESTMERCHANT1',
            'location_id' => 'LTESTLOCATION1',
            'type' => $type,
            'event_id' => 'evt_'.($dispute['id'] ?? 'dispute'),
            'created_at' => '2026-10-24T20:06:51-04:00',
            'data' => ['type' => 'dispute', 'id' => (string) ($dispute['id'] ?? ''), 'object' => ['dispute' => $dispute]],
        ];
    }

    public function test_completed_payment_restores_a_past_due_business(): void
    {
        $business = $this->business(['billing_status' => 'past_due']);
        [$record] = $this->record($business, BillingRecord::STATE_SUCCEEDED, 'sq_pay_1');

        $this->handler()->handle($this->paymentEvent('payment.updated', [
            'id' => 'sq_pay_1', 'status' => 'COMPLETED', 'customer_id' => 'sq_customer_1',
        ]));

        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.reactivated')->where('business_id', $business->id)->count());
    }

    public function test_completed_payment_resolves_the_businesss_pending_record(): void
    {
        $business = $this->business();
        [$record] = $this->record($business, BillingRecord::STATE_PENDING, null);

        $this->handler()->handle($this->paymentEvent('payment.updated', [
            'id' => 'sq_pay_new', 'status' => 'COMPLETED', 'customer_id' => 'sq_customer_1', 'order_id' => 'sq_order_new',
        ]));

        $fresh = $record->fresh();
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $fresh->state);
        $this->assertSame('sq_pay_new', $fresh->square_payment_id);
        $this->assertSame('sq_order_new', $fresh->square_order_id);
        $this->assertSame('active', $business->fresh()->billing_status);
    }

    public function test_failed_payment_fails_the_pending_record_releases_events_and_sets_past_due(): void
    {
        $business = $this->business();
        [$record, $events] = $this->record($business, BillingRecord::STATE_PENDING, null, [
            Reward::STATE_PENDING_VESTING, Reward::STATE_PENDING_VESTING,
        ]);

        $this->handler()->handle($this->paymentEvent('payment.updated', [
            'id' => 'sq_pay_failed', 'status' => 'FAILED', 'customer_id' => 'sq_customer_1',
        ]));

        $fresh = $record->fresh();
        $this->assertSame(BillingRecord::STATE_FAILED, $fresh->state);
        $this->assertSame('sq_pay_failed', $fresh->square_payment_id);
        foreach ($events as $event) {
            $this->assertNull($event->fresh()->billing_record_id);
        }
        $this->assertSame('past_due', $business->fresh()->billing_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.past_due')->where('business_id', $business->id)->count());
    }

    public function test_failed_payment_with_no_pending_record_still_sets_past_due(): void
    {
        $business = $this->business();

        $this->handler()->handle($this->paymentEvent('payment.updated', [
            'id' => 'sq_pay_failed', 'status' => 'FAILED', 'customer_id' => 'sq_customer_1',
        ]));

        $this->assertSame('past_due', $business->fresh()->billing_status);
        $this->assertSame(0, BillingRecord::count());
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.past_due')->count());
    }

    public function test_payment_for_an_unknown_customer_changes_nothing(): void
    {
        $business = $this->business();

        $this->handler()->handle($this->paymentEvent('payment.updated', [
            'id' => 'sq_pay_stray', 'status' => 'FAILED', 'customer_id' => 'sq_customer_nobody',
        ]));

        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_completed_payment_does_not_regress_a_refunded_record(): void
    {
        $business = $this->business();
        [$record] = $this->record($business, BillingRecord::STATE_REFUNDED, 'sq_pay_1');

        $this->handler()->handle($this->paymentEvent('payment.updated', [
            'id' => 'sq_pay_1', 'status' => 'COMPLETED', 'customer_id' => 'sq_customer_1',
        ]));

        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
    }

    public function test_external_refund_completed_creates_the_row_and_claws_back(): void
    {
        $business = $this->business();
        [$record, $events, $rewards] = $this->record($business, BillingRecord::STATE_SUCCEEDED, 'sq_pay_1', [
            Reward::STATE_PENDING_VESTING, Reward::STATE_PENDING_VESTING,
        ]);

        $this->handler()->handle($this->refundEvent('refund.updated', [
            'id' => 'sq_refund_ext_1', 'payment_id' => 'sq_pay_1', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 200, 'currency' => 'USD'],
        ]));

        $refund = BillingRefund::query()->where('square_refund_id', 'sq_refund_ext_1')->firstOrFail();
        $this->assertSame(BillingRefund::STATE_SUCCEEDED, $refund->state);
        $this->assertSame(200, $refund->amount_cents);
        $this->assertSame('refund_'.$record->id.'_'.$refund->id, $refund->idempotency_key);
        $this->assertSame(BillingRecord::STATE_PARTIALLY_REFUNDED, $record->fresh()->state);
        $this->assertSame('refunded', $events[0]->fresh()->coverage);
        $this->assertSame('billable', $events[1]->fresh()->coverage);
        $this->assertSame(Reward::STATE_DENIED, $rewards[0]->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[1]->fresh()->state);
    }

    public function test_refund_pending_then_completed_applies_effects_once(): void
    {
        $business = $this->business();
        [$record, $events] = $this->record($business, BillingRecord::STATE_SUCCEEDED, 'sq_pay_1', [
            Reward::STATE_PENDING_VESTING,
        ]);

        $pending = $this->refundEvent('refund.created', [
            'id' => 'sq_refund_ext_2', 'payment_id' => 'sq_pay_1', 'status' => 'PENDING',
            'amount_money' => ['amount' => 200, 'currency' => 'USD'],
        ]);
        $completed = $this->refundEvent('refund.updated', [
            'id' => 'sq_refund_ext_2', 'payment_id' => 'sq_pay_1', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 200, 'currency' => 'USD'],
        ]);

        $this->handler()->handle($pending);
        $this->assertSame(BillingRefund::STATE_PENDING, BillingRefund::sole()->state);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
        $this->assertSame('billable', $events[0]->fresh()->coverage);

        $this->handler()->handle($completed);
        $this->handler()->handle($completed);

        $this->assertSame(1, BillingRefund::count());
        $this->assertSame(BillingRefund::STATE_SUCCEEDED, BillingRefund::sole()->state);
        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
        $this->assertSame('refunded', $events[0]->fresh()->coverage);
    }

    public function test_refund_event_adopts_the_unresolved_local_pending_row(): void
    {
        $business = $this->business();
        [$record] = $this->record($business, BillingRecord::STATE_SUCCEEDED, 'sq_pay_1', [
            Reward::STATE_PENDING_VESTING,
        ]);
        $local = BillingRefund::create([
            'billing_record_id' => $record->id,
            'amount_cents' => 200,
            'state' => BillingRefund::STATE_PENDING,
        ]);
        $local->forceFill(['idempotency_key' => 'refund_'.$record->id.'_'.$local->id])->save();

        $this->handler()->handle($this->refundEvent('refund.updated', [
            'id' => 'sq_refund_ext_3', 'payment_id' => 'sq_pay_1', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 200, 'currency' => 'USD'],
        ]));

        $this->assertSame(1, BillingRefund::count());
        $fresh = $local->fresh();
        $this->assertSame('sq_refund_ext_3', $fresh->square_refund_id);
        $this->assertSame(BillingRefund::STATE_SUCCEEDED, $fresh->state);
        $this->assertSame('refund_'.$record->id.'_'.$local->id, $fresh->idempotency_key);
        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
    }

    public function test_dispute_opened_marks_the_record_and_keeps_the_business_active(): void
    {
        $business = $this->business();
        [$record] = $this->record($business, BillingRecord::STATE_SUCCEEDED, 'sq_pay_1');

        $this->handler()->handle($this->disputeEvent('dispute.created', [
            'id' => 'sq_dispute_1', 'status' => 'EVIDENCE_REQUIRED',
            'amount_money' => ['amount' => 200, 'currency' => 'USD'],
            'disputed_payment' => ['payment_id' => 'sq_pay_1'],
        ]));

        $this->assertSame(BillingRecord::STATE_DISPUTED, $record->fresh()->state);
        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertTrue($business->fresh()->flowIsActive());
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.dispute_opened')->count());
    }

    public function test_dispute_won_returns_the_record_to_succeeded(): void
    {
        $business = $this->business();
        [$record] = $this->record($business, BillingRecord::STATE_DISPUTED, 'sq_pay_1');

        $this->handler()->handle($this->disputeEvent('dispute.updated', [
            'id' => 'sq_dispute_1', 'status' => 'WON',
            'disputed_payment' => ['payment_id' => 'sq_pay_1'],
        ]));

        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.dispute_won')->count());
    }

    public function test_dispute_lost_refunds_the_record_and_claws_back_every_visit(): void
    {
        $business = $this->business();
        [$record, $events, $rewards] = $this->record($business, BillingRecord::STATE_DISPUTED, 'sq_pay_1', [
            Reward::STATE_PENDING_VESTING, Reward::STATE_PENDING_VESTING,
        ]);

        $this->handler()->handle($this->disputeEvent('dispute.updated', [
            'id' => 'sq_dispute_1', 'status' => 'LOST',
            'disputed_payment' => ['payment_id' => 'sq_pay_1'],
        ]));

        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
        foreach ($events as $event) {
            $this->assertSame('refunded', $event->fresh()->coverage);
        }
        foreach ($rewards as $reward) {
            $this->assertSame(Reward::STATE_DENIED, $reward->fresh()->state);
        }
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.dispute_lost')->count());
    }

    public function test_dispute_state_changed_event_name_resolves_the_dispute_too(): void
    {
        // Square renamed dispute.updated to dispute.state.changed
        // at the pinned API version; the staging subscription
        // carries the new name (BU-8b), so both must resolve.
        $business = $this->business();
        [$record] = $this->record($business, BillingRecord::STATE_DISPUTED, 'sq_pay_1');

        $this->handler()->handle($this->disputeEvent('dispute.state.changed', [
            'id' => 'sq_dispute_1', 'status' => 'WON',
            'disputed_payment' => ['payment_id' => 'sq_pay_1'],
        ]));

        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
    }

    public function test_unhandled_event_types_and_non_terminal_statuses_change_nothing(): void
    {
        $business = $this->business();
        [$record] = $this->record($business, BillingRecord::STATE_SUCCEEDED, 'sq_pay_1');

        $this->handler()->handle(['type' => 'invoice.payment_made', 'data' => ['object' => []]]);
        $this->handler()->handle($this->paymentEvent('payment.created', [
            'id' => 'sq_pay_1', 'status' => 'PENDING', 'customer_id' => 'sq_customer_1',
        ]));

        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertSame(0, AuditLog::count());
    }
}
