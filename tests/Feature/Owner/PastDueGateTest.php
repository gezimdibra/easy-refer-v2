<?php

namespace Tests\Feature\Owner;

use App\Models\BillingRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SV-03 (Train 2; copy Amendment 22 BILL-PD-01): a failed
 * payment is stated in the same words in three places, from
 * one shared partial: the billing page banner, a dashboard
 * gate, and the Action Center row. The raw billing status
 * word never renders on any of them.
 */
class PastDueGateTest extends TestCase
{
    use RefreshDatabase;

    private const LINE = 'A payment failed. Please update your card to keep billing current.';

    private const GRACE = 'Your QR code and customer links keep working during the grace period.';

    private function pastDueBusiness()
    {
        $business = World::business(['billing_status' => 'past_due']);
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'past-due-gate-fixture',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);

        return $business->fresh();
    }

    public function test_dashboard_gate_states_the_failure_once_with_the_fix_beside_it(): void
    {
        $owner = World::owner($this->pastDueBusiness());

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee(self::LINE);
        $page->assertSee(self::GRACE);
        $page->assertSee('href="'.route('settings.billing').'#card"', false);
        $page->assertDontSee('past_due');
        $this->assertSame(1, substr_count($page->getContent(), self::LINE));
    }

    public function test_billing_page_states_the_failure_once_from_the_same_partial(): void
    {
        $owner = World::owner($this->pastDueBusiness());

        $page = $this->actingAs($owner)->get(route('settings.billing'));
        $page->assertOk();
        $page->assertDontSee('past_due');
        $this->assertSame(1, substr_count($page->getContent(), self::LINE));
        $this->assertSame(1, substr_count($page->getContent(), self::GRACE));
    }

    public function test_active_business_dashboard_has_no_billing_gate(): void
    {
        $owner = World::owner(World::business());

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(self::LINE);
    }
}
