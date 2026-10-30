<?php

namespace Tests\Feature\Billing;

use App\Models\BillingRecord;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * P1 GRACE-DATE (critic report 2026-10-29, GRACE-DATE; the
 * SA-01 defect class): the past-due grace copy named NO DATE
 * on the dashboard banner and the billing banner for a
 * past_due business whose episode had no billing.past_due
 * audit row, even though the business's own failed billing
 * record dated the episode. The shipped SA-01 fix (Amendment
 * 35) dated only audit-backed episodes, so this state fell
 * back to the dateless keeps-working line forever, and the
 * flow never paused behind it.
 *
 * The fix: Business::dunningGraceEndsAt() derives the grace
 * end from the audit row when one exists, else from the
 * business's latest failed billing record (the same record
 * DunningService::episodeRecord() treats as the episode
 * record), so every surface that reads BillingCopy::statusLine()
 * names the real grace-end date in grace and states the
 * paused truth after it, and the pause behavior
 * (dunningPausedAt / flowIsActive) reads the same derivation.
 * Copy amendment recorded in the copy inventory.
 *
 * SUPERSEDED AT THE FLOW-STATE MERGE (2026-10-30, two-key
 * re-review Finding 1): the failed-record fallback above was
 * reversed. Failed records are also written by MonthlyCharger
 * and PendingRecordSweeper, which never set past_due, so the
 * pick was not bound to the episode and could fabricate a
 * grace date in either direction (the SA-01 outcome on a new
 * anchor). The derivation now dates ONLY from the
 * billing.past_due audit row, and an audit-missing episode
 * FAILS CLOSED: paused truth on every surface, no date, flow
 * off. The two pins below that encoded the fallback are
 * re-pinned to that truth; the audit-row precedence pin and
 * the past-grace pin stand as written.
 */
class GraceDateStateTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private const DATELESS = 'Your QR code and customer links keep working during the grace period.';

    private const PAUSED = 'Your QR code and customer links are paused until you update your card.';

    /**
     * A past_due business with NO audit row whose failed
     * charge was recorded at $failedAt.
     */
    private function undatedPastDue(string $failedAt): Business
    {
        $business = World::business(['billing_status' => 'past_due']);

        Carbon::setTestNow(Carbon::parse($failedAt));
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'grace-date-fixture-'.$business->id,
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        Carbon::setTestNow(null);

        return $business->fresh();
    }

    // RE-PINNED 2026-10-30 (two-key Finding 1): an episode
    // with a failed record but NO audit row cannot be dated
    // from that record (the pick is not bound to the
    // episode), so it fails closed even inside what the old
    // fallback math called the grace window (failed
    // 2026-03-10, viewed 2026-03-12): the paused truth
    // renders on the dashboard gate, the billing banner, and
    // the Action Center row; no date is named anywhere; the
    // dateless line is absent; the flow is off.
    public function test_audit_missing_episode_fails_closed_on_every_surface(): void
    {
        $business = $this->undatedPastDue('2026-03-10 02:00:00');
        Carbon::setTestNow('2026-03-12 12:00:00');
        $owner = World::owner($business);

        $this->assertNull($business->dunningGraceEndsAt(), 'no audit row, no date: the failed record dates nothing');

        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee(self::PAUSED);
            $page->assertDontSee(self::DATELESS);
            $page->assertDontSee('keeps working');
            $page->assertDontSee('keep working until');
        }

        $this->assertTrue($business->dunningPausedAt());
        $this->assertFalse($business->flowIsActive(), 'undatable episode: the flow the copy calls paused is paused');
    }

    // Past the grace end the same derivation yields: no
    // surface claims the code works, the paused truth renders
    // on every surface, and the flow itself is paused behind
    // the copy (the two can never disagree again).
    public function test_undated_episode_past_grace_end_claims_nothing_works(): void
    {
        $business = $this->undatedPastDue('2026-03-10 02:00:00');
        Carbon::setTestNow('2026-03-20 12:00:00');
        $owner = World::owner($business);

        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee(self::PAUSED);
            $page->assertDontSee('keeps working');
            $page->assertDontSee('keep working until');
            $page->assertDontSee(self::DATELESS);
        }

        $this->assertTrue($business->dunningPausedAt());
        $this->assertFalse($business->flowIsActive(), 'past grace end, the flow the copy calls paused is paused');
    }

    // Precedence: when the audit row exists it stays the
    // episode of record (the SA-01 derivation); a failed
    // record from an earlier, recovered episode does not
    // move the date.
    public function test_the_audit_row_stays_the_episode_of_record_when_it_exists(): void
    {
        $business = World::business(['billing_status' => 'past_due']);

        Carbon::setTestNow(Carbon::parse('2026-03-01 02:00:00'));
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'grace-date-earlier-episode',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_pay_failed'], $business, null);
        Carbon::setTestNow(null);

        // The audit row was also written at the March 1 test
        // instant above; restamp the episode to March 10 so
        // the two candidate starts differ by 9 days.
        Carbon::setTestNow(Carbon::parse('2026-03-10 02:00:00'));
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_pay_failed_2'], $business->fresh(), null);
        Carbon::setTestNow('2026-03-12 12:00:00');

        $this->assertSame(
            'March 16, 2026',
            $business->fresh()->localTime($business->fresh()->dunningGraceEndsAt())->format('F j, Y'),
            'the latest audit row (Mar 10) + 7 days, not the failed record (Mar 1) + 7 days'
        );
    }

    // RE-PINNED 2026-10-30 (two-key Finding 1): past_due with
    // NEITHER record (unproducible by the webhook) fails
    // closed like any undatable episode. No date is invented
    // AND the dateless keeps-working line never renders (the
    // string is deleted from the app); the paused truth is
    // the only line.
    public function test_past_due_with_no_record_anywhere_fails_closed_and_invents_no_date(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $owner = World::owner($business);

        $this->assertNull($business->dunningGraceEndsAt());
        $this->assertTrue($business->dunningPausedAt());

        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertOk()
            ->assertSee(self::PAUSED)
            ->assertDontSee(self::DATELESS);
    }
}
