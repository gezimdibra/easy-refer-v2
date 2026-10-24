<?php

namespace Tests\Feature\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The nightly collection step (spec Section 9.5, BILL-13,
 * BILL-39): settlement outcomes persist onto the events, the
 * free-visit credit decrements atomically and never goes
 * negative, and after persistence a fresh dashboard walk agrees
 * with the persisted coverage event for event, which is the
 * reconciliation the whole design rests on.
 */
class SettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 4000;

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550989', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function event(Business $business, Membership $membership, int $amountCents = 200): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        return BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
        ]);
    }

    public function test_collection_persists_coverage_and_consumes_the_credit(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);

        $events = [];
        for ($i = 0; $i < 7; $i++) {
            $events[] = $this->event($business, $membership);
        }

        app(SettlementService::class)->settleAndPersist($business);

        $coverages = array_map(fn (BillingEvent $e) => $e->fresh()->coverage, $events);
        $this->assertSame(
            ['free_credit', 'free_credit', 'free_credit', 'free_credit', 'free_credit', 'billable', 'billable'],
            $coverages,
        );
        $this->assertSame(0, $business->fresh()->free_visits_remaining);
    }

    public function test_second_collection_consumes_nothing_and_agrees_with_the_walk(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);

        for ($i = 0; $i < 7; $i++) {
            $this->event($business, $membership);
        }

        $service = app(SettlementService::class);
        $service->settleAndPersist($business);

        $extra = $this->event($business, $membership);
        $service->settleAndPersist($business->fresh());

        // The new event is billable (the credit is spent), the
        // balance never went negative, and the persisted coverage
        // of every event equals a fresh walk's outcome.
        $this->assertSame('billable', $extra->fresh()->coverage);
        $this->assertSame(0, $business->fresh()->free_visits_remaining);

        $settled = (new VisitValuation)->settle($business->fresh());
        foreach (BillingEvent::query()->where('business_id', $business->id)->get() as $event) {
            $this->assertSame(
                $settled['settled'][$event->id]['coverage'],
                $event->coverage,
                'persisted coverage disagrees with the walk for event '.$event->id,
            );
        }
    }

    public function test_credit_decrement_never_goes_negative(): void
    {
        $business = World::business(['free_visits_remaining' => 2]);
        $membership = $this->membership($business);

        $events = [];
        for ($i = 0; $i < 4; $i++) {
            $events[] = $this->event($business, $membership);
        }

        app(SettlementService::class)->settleAndPersist($business);

        $this->assertSame(0, $business->fresh()->free_visits_remaining);
        $this->assertSame(
            ['free_credit', 'free_credit', 'billable', 'billable'],
            array_map(fn (BillingEvent $e) => $e->fresh()->coverage, $events),
        );
    }

    public function test_collection_persists_the_cap_tail(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        for ($i = 0; $i < 55; $i++) {
            $this->event($business, $membership);
        }

        app(SettlementService::class)->settleAndPersist($business);

        $this->assertSame(49, BillingEvent::query()->where('coverage', 'billable')->count());
        $this->assertSame(6, BillingEvent::query()->where('coverage', 'over_cap')->count());
    }
}
