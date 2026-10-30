<?php

namespace Tests\Feature\Billing;

use App\Models\BillingEvent;
use App\Models\Plan;
use App\Services\Billing\VisitValuation;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Per-visit metering pins (Phase 4 coverage map row 1): a verified
 * visit writes exactly one billing event, the amount always comes
 * from the plan row (never a literal), no event exists for held,
 * voided, or unredeemed referrals, and an event is written even
 * when settlement will later call the visit free or over-cap,
 * because coverage is a settlement outcome, not a write-time
 * decision.
 */
class MeteringPinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_redemption_writes_exactly_one_event_at_the_plan_price(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550996', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550201', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $this->assertSame('redeemed', $result['outcome']);
        $this->assertSame(1, BillingEvent::count());
        $event = BillingEvent::firstOrFail();
        $this->assertSame($referral->id, $event->referral_id);
        $this->assertSame($business->id, $event->business_id);
        $this->assertSame(200, $event->amount_cents);
        $this->assertNull($event->billing_record_id);
    }

    public function test_replayed_redemption_never_writes_a_second_event(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550995', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550202', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $service = app(RedemptionService::class);
        $first = $service->redeemReferral($referral, null, '127.0.0.1');
        $second = $service->redeemReferral($referral->fresh(), null, '127.0.0.1');

        $this->assertSame('redeemed', $first['outcome']);
        $this->assertNotSame('redeemed', $second['outcome']);
        $this->assertSame(1, BillingEvent::count());
    }

    public function test_event_amount_is_read_from_the_plan_row_never_a_literal(): void
    {
        $plan = Plan::create([
            'key' => 'metering_probe',
            'name' => 'Metering Probe',
            'model' => 'per_referral',
            'price_cents' => null,
            'per_referral_cents' => 350,
            'included_referrals_per_period' => null,
            'monthly_cap_cents' => 9900,
            'active' => true,
        ]);

        $business = World::business(['current_plan_id' => $plan->id]);
        $referrer = World::customer('+12125550994', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550203', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $this->assertSame(350, BillingEvent::firstOrFail()->amount_cents);
    }

    public function test_flagged_referral_writes_one_held_event(): void
    {
        // Option B (2026-10-28): the pre-conversion pin (a held
        // referral writes NO event, outcome 'held') is retired —
        // a flagged referral now completes its redemption and
        // writes exactly one billing event, coverage 'held',
        // which the settlement walk excludes until release.
        $business = World::business();
        $referrer = World::customer('+12125550993', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550204', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'risk_score' => 50, 'risk_rules' => ['device_signal_reuse'],
        ]);

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $this->assertSame('redeemed', $result['outcome']);
        $this->assertSame(1, BillingEvent::count());
        $this->assertSame('held', BillingEvent::firstOrFail()->coverage);
    }

    // Legacy shape (§7.9): this denyReferral pin exercises the
    // pre-Option-B referral-level drain for legacy held referrals — a
    // shape no live path creates since the conversion — kept to prove
    // the legacy drain still behaves (a voided referral writes no event).
    public function test_voided_referral_writes_no_event(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550992', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550205', 'Fri', 'End');
        // BREAKER D-2: deny resolves a held review, so the legacy
        // held shape this pin exercises is fixtured as held.
        [$referral] = World::referral($membership, $friend, ['hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse']]);

        $service = app(RedemptionService::class);
        $this->assertTrue($service->denyReferral($referral));

        $result = $service->redeemReferral($referral->fresh(), null, '127.0.0.1');

        $this->assertNotSame('redeemed', $result['outcome']);
        $this->assertSame(0, BillingEvent::count());
    }

    public function test_pending_unredeemed_referral_has_no_event(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550991', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550206', 'Fri', 'End');
        World::referral($membership, $friend);

        $this->assertSame(0, BillingEvent::count());
    }

    public function test_event_is_written_even_when_settlement_will_call_it_over_cap(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $referrer = World::customer('+12125550990', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        // 55 visits already settled this month: the cap is spent.
        for ($i = 0; $i < 55; $i++) {
            $past = World::customer('+121255503'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$pastReferral] = World::referral($membership, $past);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $pastReferral->id,
                'amount_cents' => 200,
            ]);
        }

        $friend = World::customer('+12125550207', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $this->assertSame('redeemed', $result['outcome']);
        $event = BillingEvent::query()->where('referral_id', $referral->id)->firstOrFail();
        // The write itself carries the undecided initial coverage;
        // settlement is what labels the visit over-cap.
        $this->assertSame('billable', $event->coverage);

        $settled = app(VisitValuation::class)->settle($business->fresh());
        $this->assertSame('over_cap', $settled['settled'][$event->id]['coverage']);
        $this->assertSame(0, $settled['settled'][$event->id]['fee_cents']);
    }
}
