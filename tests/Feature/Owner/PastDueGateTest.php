<?php

namespace Tests\Feature\Owner;

use App\Models\BillingRecord;
use App\Models\Business;
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

    // The fixture seeds the real webhook shape: the failed
    // record AND its billing.past_due audit row (SquareWebhook
    // Handler writes both in one transaction), so the episode
    // dates from the audit row and the line names that
    // grace-end date; the retired dateless grace line never
    // renders. (Two-key Finding 1 fix, 2026-10-30: the earlier
    // fixture seeded no audit row and relied on the failed
    // record to date the episode — that unbound fallback is
    // removed; an audit-missing episode fails closed instead,
    // pinned in FlowStateTruthTest.)
    private function graceLine($business): string
    {
        $graceEnd = $business->fresh()->dunningGraceEndsAt();
        $this->assertNotNull($graceEnd);

        return 'Your QR code keeps working until '
            .$business->localTime($graceEnd)->format('F j, Y')
            .'. Update your card by then to keep it on.';
    }

    private function pastDueBusiness()
    {
        $business = World::business(['billing_status' => 'past_due']);
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'past-due-gate-fixture',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_past_due_gate'], $business, $record);

        return $business->fresh();
    }

    public function test_dashboard_gate_states_the_failure_once_with_the_fix_beside_it(): void
    {
        $business = $this->pastDueBusiness();
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee(self::LINE);
        $page->assertSee($this->graceLine($owner->business));
        $page->assertDontSee('keep working during the grace period');
        $page->assertSee('href="'.route('settings.billing').'#card"', false);
        $page->assertDontSee('past_due');
        $this->assertSame(1, substr_count($page->getContent(), self::LINE));
    }

    public function test_billing_page_states_the_failure_once_from_the_same_partial(): void
    {
        $business = $this->pastDueBusiness();
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('settings.billing'));
        $page->assertOk();
        $page->assertDontSee('past_due');
        $this->assertSame(1, substr_count($page->getContent(), self::LINE));
        $this->assertSame(1, substr_count($page->getContent(), $this->graceLine($owner->business)));
        $page->assertDontSee('keep working during the grace period');
    }

    public function test_active_business_dashboard_has_no_billing_gate(): void
    {
        $owner = World::owner(World::business());

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(self::LINE);
    }
}
