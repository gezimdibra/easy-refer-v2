<?php

namespace Tests\Feature\Billing;

use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train 3.5 (retro 2026-10-25, business-logic audit Section 4
 * seam 4): a billing record's window is the span of the events
 * it claimed, not a calendar month; after a carry-forward it
 * covers parts of two calendar months. The window was printed
 * from the record's raw UTC period dates, so an edge event
 * (Sep 30, 01:30 UTC) rendered on its UTC date instead of the
 * owner's local date (Sep 29 in New York), and nothing said
 * the window spanned two months. The statement and the
 * billing history now name the window in business-local terms
 * (Business::localTime over the claimed rows' own instants)
 * and label a two-month window as one. The window itself and
 * its accounting do not move (FE-BILL-01 shape stays).
 */
class BillingStatementLocalTimeTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 9600;

    private int $keySequence = 0;

    private function record(Business $business, string $periodStart, string $periodEnd, string $createdAt): BillingRecord
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 400,
            'currency' => 'USD',
            'idempotency_key' => 'batch_stmt_lt_'.(++$this->keySequence),
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
        $record->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $record->fresh();
    }

    private function claimedVisit(Business $business, BillingRecord $record, string $at): void
    {
        $referrer = World::customer('+12125550989', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'billing_record_id' => $record->id,
            'amount_cents' => 200,
            'coverage' => 'billable',
        ]);
        $event->forceFill(['created_at' => $at])->save();
    }

    public function test_statement_window_is_named_in_local_time_and_labeled_when_it_spans_two_months(): void
    {
        $business = World::business(['billing_status' => 'active', 'timezone' => 'America/New_York']);
        $owner = World::owner($business);

        // The charger's own stored window (UTC dates of the
        // claimed instants): Sep 30 to Oct 24.
        $record = $this->record($business, '2026-09-30', '2026-10-24', '2026-11-01 03:00:00');
        // Sep 30, 01:30 UTC is Sep 29, 21:30 in New York.
        $this->claimedVisit($business, $record, '2026-09-30 01:30:00');
        $this->claimedVisit($business, $record, '2026-10-24 12:00:00');

        $response = $this->actingAs($owner)->get(route('settings.billing.statement', $record));

        $response->assertOk();
        $response->assertSee('Sep 29 to Oct 24, 2026');
        $response->assertDontSee('Sep 30 to Oct 24, 2026');
        // The two-month window is labeled as one, naming both
        // months and the carry-forward (Train 3.5 label).
        $response->assertSee('This charge covers part of September and part of October. The earlier part carried forward into this charge.');
    }

    public function test_statement_window_inside_one_month_carries_no_two_month_label(): void
    {
        $business = World::business(['billing_status' => 'active', 'timezone' => 'America/New_York']);
        $owner = World::owner($business);

        $record = $this->record($business, '2026-09-03', '2026-09-24', '2026-10-01 03:00:00');
        $this->claimedVisit($business, $record, '2026-09-03 12:00:00');
        $this->claimedVisit($business, $record, '2026-09-24 12:00:00');

        $response = $this->actingAs($owner)->get(route('settings.billing.statement', $record));

        $response->assertOk();
        $response->assertSee('Sep 3 to Sep 24, 2026');
        $response->assertDontSee('carried forward into this charge');
    }

    public function test_statement_window_uses_the_business_zone_not_the_default(): void
    {
        $business = World::business(['billing_status' => 'active', 'timezone' => 'America/Los_Angeles']);
        $owner = World::owner($business);

        // Oct 1, 04:30 UTC is Sep 30, 21:30 in Los Angeles: the
        // stored UTC window opens Oct 1, the owner's window
        // opens Sep 30.
        $record = $this->record($business, '2026-10-01', '2026-10-01', '2026-11-01 03:00:00');
        $this->claimedVisit($business, $record, '2026-10-01 04:30:00');

        $response = $this->actingAs($owner)->get(route('settings.billing.statement', $record));

        $response->assertOk();
        $response->assertSee('Sep 30 to Sep 30, 2026');
        $response->assertDontSee('Oct 1 to Oct 1, 2026');
    }

    public function test_billing_history_names_the_charge_and_its_window_in_local_time(): void
    {
        $business = World::business(['billing_status' => 'active', 'timezone' => 'America/New_York']);
        $owner = World::owner($business);

        // Charged Nov 1, 03:00 UTC: Oct 31, 23:00 in New York.
        $record = $this->record($business, '2026-09-30', '2026-10-24', '2026-11-01 03:00:00');
        $this->claimedVisit($business, $record, '2026-09-30 01:30:00');
        $this->claimedVisit($business, $record, '2026-10-24 12:00:00');

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertSee('Oct 31, 2026');
        $response->assertSee('Sep 29 to Oct 24, 2026');
        $response->assertDontSee('Nov 1, 2026');
        $response->assertDontSee('Sep 30 to Oct 24, 2026');
    }
}
