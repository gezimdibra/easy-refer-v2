<?php

namespace Tests\Feature\Billing;

use App\Contracts\SmsGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Services\Billing\RefundService;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Refunds and the OD-3 reward clawback (spec Section 9.8;
 * BILL-27, BILL-28, EC-31, EC-32; SQ-14, SQ-15, SQ-16): a
 * refunded visit is marked refunded; its still-pending reward
 * cancels automatically with a clawback row and its queued text
 * can never fire; a vested or claimed reward is never silently
 * reversed and surfaces in the owner's review queue instead; a
 * sibling's reward is never touched.
 */
class RefundClawbackTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 6000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550987', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    /**
     * A funded batch: a succeeded record with one billed event
     * (and reward) per visit, in creation order.
     *
     * @param  list<string>  $rewardStates
     * @return array{0: BillingRecord, 1: list<BillingEvent>, 2: list<Reward>}
     */
    private function fundedBatch(Business $business, Membership $membership, array $rewardStates): array
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200 * count($rewardStates),
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_2026-03-01_1',
            'square_payment_id' => 'sq_payment_batch',
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        $events = [];
        $rewards = [];
        foreach ($rewardStates as $state) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED]);
            [$reward] = World::reward($membership, $referral, [
                'state' => $state,
                'available_at' => $state === Reward::STATE_PENDING_VESTING ? now()->addDays(14) : now()->subDay(),
                'notification_state' => $state === Reward::STATE_PENDING_VESTING ? 'queued' : 'sent',
                'claimed_at' => $state === Reward::STATE_CLAIMED ? now()->subDay() : null,
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

    public function test_full_refund_cancels_the_pending_reward_with_a_clawback_row(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events, $rewards] = $this->fundedBatch($business, $membership, [Reward::STATE_PENDING_VESTING]);

        $refund = app(RefundService::class)->refund($record, null, 'owner_request', 'owner');

        $this->assertSame(BillingRefund::STATE_SUCCEEDED, $refund->state);
        $this->assertSame('sq_refund_fake_1', $refund->square_refund_id);
        $this->assertSame('refund_'.$record->id.'_'.$refund->id, $refund->idempotency_key);
        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
        $this->assertSame('refunded', $events[0]->fresh()->coverage);
        $this->assertSame(Reward::STATE_DENIED, $rewards[0]->fresh()->state);

        $clawback = ReferralEvent::query()->where('event_type', 'clawback')->firstOrFail();
        $this->assertSame($rewards[0]->id, $clawback->reward_id);
        $this->assertSame('canceled', $clawback->metadata['outcome']);
    }

    public function test_canceled_rewards_queued_text_never_fires(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, , $rewards] = $this->fundedBatch($business, $membership, [Reward::STATE_PENDING_VESTING]);

        app(RefundService::class)->refund($record);

        // Past the vesting date, the sweep must not vest or text
        // a canceled reward.
        $this->travel(30)->days();
        $this->artisan('rewards:notify-due')->assertSuccessful();

        $this->assertSame(Reward::STATE_DENIED, $rewards[0]->fresh()->state);
        $this->assertSame('queued', $rewards[0]->fresh()->notification_state);
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_partial_refund_of_one_visits_worth_refunds_exactly_one_visit(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events, $rewards] = $this->fundedBatch($business, $membership, [
            Reward::STATE_PENDING_VESTING, Reward::STATE_PENDING_VESTING, Reward::STATE_PENDING_VESTING,
        ]);

        app(RefundService::class)->refund($record, 200);

        // SQ-15: the earliest visit absorbs the refund; the
        // other two are untouched.
        $this->assertSame(BillingRecord::STATE_PARTIALLY_REFUNDED, $record->fresh()->state);
        $this->assertSame('refunded', $events[0]->fresh()->coverage);
        $this->assertSame('billable', $events[1]->fresh()->coverage);
        $this->assertSame('billable', $events[2]->fresh()->coverage);
        $this->assertSame(Reward::STATE_DENIED, $rewards[0]->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[1]->fresh()->state);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[2]->fresh()->state);

        app(RefundService::class)->refund($record->fresh(), 400);
        $this->assertSame(BillingRecord::STATE_REFUNDED, $record->fresh()->state);
        $this->assertSame('refunded', $events[1]->fresh()->coverage);
        $this->assertSame('refunded', $events[2]->fresh()->coverage);
    }

    public function test_refund_after_vesting_surfaces_a_review_hold_instead_of_reversing(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events, $rewards] = $this->fundedBatch($business, $membership, [Reward::STATE_AVAILABLE]);

        app(RefundService::class)->refund($record);

        // SQ-16/BILL-28: never silently reversed; the owner sees
        // it in the review queue (held rewards), with the
        // clawback row explaining why.
        $reward = $rewards[0]->fresh();
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->state);
        $this->assertTrue($reward->hold);
        $this->assertContains('refund_clawback', $reward->hold_rules);
        $this->assertSame('refunded', $events[0]->fresh()->coverage);

        $clawback = ReferralEvent::query()->where('event_type', 'clawback')->firstOrFail();
        $this->assertSame('review', $clawback->metadata['outcome']);

        // A replayed clawback changes nothing twice.
        $outcome = app(\App\Services\Billing\RewardClawback::class)->clawbackEvent($events[0]->fresh());
        $this->assertSame('review', $outcome);
        $this->assertSame(1, ReferralEvent::query()->where('event_type', 'clawback')->count());
    }

    public function test_claimed_reward_is_never_silently_reversed(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, , $rewards] = $this->fundedBatch($business, $membership, [Reward::STATE_CLAIMED]);

        app(RefundService::class)->refund($record);

        $reward = $rewards[0]->fresh();
        $this->assertSame(Reward::STATE_CLAIMED, $reward->state);
        $this->assertTrue($reward->hold);
    }

    public function test_refund_guards_reject_unrefundable_records_and_amounts(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record] = $this->fundedBatch($business, $membership, [Reward::STATE_PENDING_VESTING]);
        $service = app(RefundService::class);

        try {
            $service->refund($record, 0);
            $this->fail('A zero refund must be refused.');
        } catch (InvalidArgumentException) {
        }

        try {
            $service->refund($record, 201);
            $this->fail('An over-refund must be refused.');
        } catch (InvalidArgumentException) {
        }

        $pending = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200,
            'idempotency_key' => 'perref_'.$business->id.'_2026-03-02_1',
            'state' => BillingRecord::STATE_PENDING,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $service->refund($pending, 200);
    }

    public function test_failed_refund_changes_nothing(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$record, $events, $rewards] = $this->fundedBatch($business, $membership, [Reward::STATE_PENDING_VESTING]);
        $this->gateway->refundFailureCode = 'REFUND_NOT_POSSIBLE';

        $refund = app(RefundService::class)->refund($record);

        $this->assertSame(BillingRefund::STATE_FAILED, $refund->state);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
        $this->assertSame('billable', $events[0]->fresh()->coverage);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $rewards[0]->fresh()->state);
        $this->assertSame(0, ReferralEvent::query()->where('event_type', 'clawback')->count());
    }

    // Legacy shape (§7.9): this denyReferral pin exercises the
    // pre-Option-B referral-level drain for legacy held referrals — a
    // shape no live path creates since the conversion — kept to prove
    // the legacy drain still behaves (deny is refused after redemption).
    public function test_deny_after_redeem_is_refused_so_a_billed_visit_cannot_be_voided(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        $friend = World::customer('+12125550666', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $service = app(RedemptionService::class);
        $service->redeemReferral($referral, null, '127.0.0.1');

        // Coverage map row 6: the void path is a pre-completion
        // remedy; once redeemed, the referral (and its billing
        // event and reward) cannot be voided out from under the
        // ledger.
        $this->assertFalse($service->denyReferral($referral->fresh()));
        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);
        $this->assertSame(1, BillingEvent::query()->where('referral_id', $referral->id)->count());
    }
}
