<?php

namespace Tests\Feature\Billing;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\SettlementService;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use App\Contracts\SquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Money fix train, fix 4 (business-logic audit 1.3 and 3.4;
 * synthesis item 4, coordinator ruling): a visit whose reward
 * is fraud-denied is NOT billable and restores its free slot;
 * a held visit bills only when released.
 *
 * Before this train the anomaly rules held only the reward:
 * the visit counted and billed at redemption, a denied reward
 * left the charge standing, and a fraud-denied visit inside
 * the first five burned a free slot forever. The billing
 * ledger now carries two extra coverage states for the
 * pre-charge lifecycle: held (minted under an anomaly hold,
 * excluded from settlement until release) and fraud_denied
 * (excluded permanently; the free credit it had consumed is
 * handed back). A visit already charged under a settled
 * record is ledger history: deny leaves it alone and the
 * refund path (fix 2) is the remedy.
 */
class FraudDeniedBillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 8000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        config(['billing.charges_enabled' => true]);
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

    /**
     * BREAKER D-2 (2026-10-29): deny is the resolution of a held
     * review, so a reward an owner denies in these billing pins
     * is first placed under the review hold the deny resolves.
     * The billing mechanics under test are unchanged.
     */
    private function holdForReview(Reward $reward): Reward
    {
        $reward->forceFill(['hold' => true, 'held_at' => now(), 'hold_rules' => ['review']])->save();

        return $reward->fresh();
    }

    /** Redeem one fresh pending referral through the real service. */
    private function redeemOne(Business $business, Membership $membership): array
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        // Referrals created minutes ago carry the (separate)
        // age signal only; billing behavior here is unaffected.
        $referral->forceFill(['created_at' => now()->subHours(3)])->save();

        return app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
    }

    public function test_fraud_denied_billable_visit_never_charges(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $result = $this->redeemOne($business, $membership);
        $this->assertSame('redeemed', $result['outcome']);
        $event = BillingEvent::query()->where('referral_id', $result['referral']->id)->firstOrFail();

        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame('billable', $event->fresh()->coverage);
        $this->assertSame(200, app(SettlementService::class)->accruedPreview($business->fresh())['accrued_cents']);

        $this->assertTrue(app(RedemptionService::class)->denyReward($this->holdForReview($result['reward'])));

        $this->assertSame('fraud_denied', $event->fresh()->coverage);
        $this->assertSame(0, app(SettlementService::class)->accruedPreview($business->fresh())['accrued_cents']);
        $this->assertNull(app(MonthlyCharger::class)->charge($business->fresh()));
        $this->assertSame(0, BillingRecord::count());
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    public function test_fraud_denied_free_visit_restores_the_free_slot(): void
    {
        $business = World::business(['free_visits_remaining' => 5, 'daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $membership = $this->membership($business);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->redeemOne($business, $membership);
        }
        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame(0, $business->fresh()->free_visits_remaining);

        // Deny the second visit as fraud: its free slot returns.
        $deniedEvent = BillingEvent::query()->where('referral_id', $results[1]['referral']->id)->firstOrFail();
        $this->assertSame('free_credit', $deniedEvent->fresh()->coverage);
        $this->assertTrue(app(RedemptionService::class)->denyReward($this->holdForReview($results[1]['reward'])));

        $this->assertSame('fraud_denied', $deniedEvent->fresh()->coverage);
        $this->assertSame(1, $business->fresh()->free_visits_remaining);

        // The next visit spends the restored slot instead of
        // billing: six visits, five free, one fraud-denied, and
        // still nothing accrued.
        $sixth = $this->redeemOne($business, $membership);
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $sixthEvent = BillingEvent::query()->where('referral_id', $sixth['referral']->id)->firstOrFail();
        $this->assertSame('free_credit', $sixthEvent->fresh()->coverage);
        $this->assertSame(0, $business->fresh()->free_visits_remaining);
        $this->assertSame(0, app(SettlementService::class)->accruedPreview($business->fresh())['accrued_cents']);
    }

    public function test_fraud_deny_before_settlement_still_excludes_the_visit(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        $result = $this->redeemOne($business, $membership);
        $event = BillingEvent::query()->where('referral_id', $result['referral']->id)->firstOrFail();

        $this->assertTrue(app(RedemptionService::class)->denyReward($this->holdForReview($result['reward'])));
        $this->assertSame('fraud_denied', $event->fresh()->coverage);

        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame('fraud_denied', $event->fresh()->coverage);
        $this->assertSame(0, app(SettlementService::class)->accruedPreview($business->fresh())['accrued_cents']);
    }

    public function test_held_visit_bills_only_when_released(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        // Referrer concentration setup (the RiskAndReleaseTest
        // pattern): seven redemptions by others, three by this
        // referrer, so the next mint holds for concentration.
        for ($i = 0; $i < 7; $i++) {
            $other = World::customer('+1212555'.str_pad((string) (300 + $i), 4, '0', STR_PAD_LEFT), 'Oth', 'Er');
            [$m] = World::membership($business, $other);
            World::referral($m, World::customer('+1212555'.str_pad((string) (400 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            World::referral($membership, World::customer('+1212555'.str_pad((string) (500 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
            ]);
        }

        $result = $this->redeemOne($business, $membership);
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertTrue($result['reward']->hold);
        $this->assertContains('referrer_concentration', $result['reward']->hold_rules);

        $event = BillingEvent::query()->where('referral_id', $result['referral']->id)->firstOrFail();
        $this->assertSame('held', $event->fresh()->coverage);

        // While held: no accrual, no charge.
        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame('held', $event->fresh()->coverage);
        $this->assertSame(0, app(SettlementService::class)->accruedPreview($business->fresh())['accrued_cents']);
        $this->assertNull(app(MonthlyCharger::class)->charge($business->fresh()));

        // Released: the visit settles and bills normally.
        app(RedemptionService::class)->releaseReward($result['reward']);
        $this->assertSame('billable', $event->fresh()->coverage);
        $record = app(MonthlyCharger::class)->charge($business->fresh());
        $this->assertNotNull($record);
        $this->assertSame(200, $record->amount_cents);
        $this->assertSame($record->id, $event->fresh()->billing_record_id);
    }

    public function test_held_visit_denied_instead_of_released_never_bills(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        $friend = World::customer('+12125550888', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        [$reward] = World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'hold' => true,
            'hold_rules' => ['redemption_burst'],
            'held_at' => now(),
            'available_at' => now()->addDays(14),
            'notification_state' => 'queued',
        ]);
        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => 200,
            'coverage' => 'held',
        ]);

        $this->assertTrue(app(RedemptionService::class)->denyReward($reward));
        $this->assertSame('fraud_denied', $event->fresh()->coverage);

        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame(0, app(SettlementService::class)->accruedPreview($business->fresh())['accrued_cents']);
    }

    public function test_deny_after_the_visit_was_charged_leaves_the_ledger_alone(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);

        $result = $this->redeemOne($business, $membership);
        $record = app(MonthlyCharger::class)->charge($business);
        $this->assertNotNull($record);
        $event = BillingEvent::query()->where('referral_id', $result['referral']->id)->firstOrFail();
        $this->assertSame($record->id, $event->fresh()->billing_record_id);

        // The reward still denies (held for review after the
        // charge; BREAKER D-2 makes the hold the deny's entry
        // condition), but a settled record's events are history:
        // un-billing a charged visit is the refund path's job
        // (fix 2).
        $this->assertTrue(app(RedemptionService::class)->denyReward($this->holdForReview($result['reward'])));
        $this->assertSame('billable', $event->fresh()->coverage);
        $this->assertSame($record->id, $event->fresh()->billing_record_id);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
    }

    public function test_cap_and_free_boundaries_hold_around_a_denied_visit(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $membership = $this->membership($business);

        // 50 visits at the ledger level (the settlement walk is
        // the subject here; redeeming 50 visits for one
        // referrer through the service would correctly trip the
        // anomaly holds instead): 49 billable to the cap, 1
        // over cap. Deny visit 10 as fraud: the deny removes
        // the visit from the walk, so the former over-cap visit
        // becomes the 49th billable one and the month total
        // stays exactly at the cap, never above it.
        $rewards = [];
        for ($i = 0; $i < 50; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED]);
            [$reward] = World::reward($membership, $referral, [
                'state' => Reward::STATE_PENDING_VESTING,
                'available_at' => now()->addDays(14),
                'notification_state' => 'queued',
            ]);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
            ]);
            $rewards[] = $reward;
        }
        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame(9800, app(SettlementService::class)->accruedPreview($business->fresh())['accrued_cents']);

        $this->assertTrue(app(RedemptionService::class)->denyReward($this->holdForReview($rewards[9])));
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $preview = app(SettlementService::class)->accruedPreview($business->fresh());
        $this->assertSame(9800, $preview['accrued_cents']);
        $this->assertSame(49, $preview['billable_events']);
    }
}
