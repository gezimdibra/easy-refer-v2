<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PlacementCoachingEvent;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use App\Services\Owner\PlacementCoaching;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-04 placement coaching (design pass Sections 1.4, 1.5, 1.6,
 * 4.1, 4.2, 5.3, 7.1): the common-window normalization, the
 * conjunctive floor, the move table, and the suppression
 * contract, exercised at the service level. The card's HTTP
 * rendering is pinned in PlacementCoachingCardTest.
 */
class PlacementCoachingTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-28 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function coaching(): PlacementCoaching
    {
        return app(PlacementCoaching::class);
    }

    /** A flow-active business whose four codes carry the given ages (days). */
    private function businessWithAges(array $ages): Business
    {
        $business = World::business();
        $codes = QrCode::ensurePlacements($business);
        foreach ($ages as $placement => $days) {
            $codes[$placement]->forceFill(['created_at' => $this->now->copy()->subDays($days)])->save();
        }

        return $business->fresh();
    }

    private function seedScanAt(Business $business, string $placement, Carbon $when, bool $withPlacement = true): void
    {
        Carbon::setTestNow($when);
        ReferralEvent::record('scan', 'customer', $business, null, null, null,
            $withPlacement ? ['placement' => $placement] : []);
        Carbon::setTestNow($this->now);
    }

    private function seedScans(Business $business, string $placement, int $count, int $daysAgo): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->seedScanAt($business, $placement, $this->now->copy()->subDays($daysAgo)->addMinutes($i));
        }
    }

    /** The worked-example-2 shape: window 12 in W, counter 2 in W, both codes 60 days old. */
    private function firingBusiness(): Business
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'counter', 2, 5);
        $this->seedScans($business, 'table', 5, 5);
        $this->seedScans($business, 'receipt', 5, 5);

        return $business;
    }

    // ---------- Window math (Section 1.4) ----------

    public function test_window_is_the_youngest_eligible_age_capped_at_28(): void
    {
        $young = $this->businessWithAges(['counter' => 92, 'window' => 21, 'table' => 10, 'receipt' => 10]);
        $this->assertSame(21, $this->coaching()->windowDaysFor($young));

        $old = $this->businessWithAges(['counter' => 60, 'window' => 45, 'table' => 90, 'receipt' => 30]);
        $this->assertSame(28, $this->coaching()->windowDaysFor($old));

        // A code inside its 14-day grace is not eligible and does
        // not shrink the window for the codes that are.
        $mixed = $this->businessWithAges(['counter' => 10, 'window' => 40, 'table' => 35, 'receipt' => 5]);
        $this->assertSame(28, $this->coaching()->windowDaysFor($mixed));

        // Fewer than two eligible codes: no window at all.
        $solo = $this->businessWithAges(['counter' => 60, 'window' => 5, 'table' => 5, 'receipt' => 5]);
        $this->assertNull($this->coaching()->windowDaysFor($solo));
    }

    public function test_windowed_counts_ignore_lifetime_and_other_windows(): void
    {
        // Worked example 1 (Section 1.4): window poster age 92,
        // lifetime 36 (27 of them 40 days ago, 9 in the last 21
        // days); table tent age 21, lifetime 6, all recent.
        $business = $this->businessWithAges(['counter' => 92, 'window' => 92, 'table' => 21, 'receipt' => 92]);
        $this->seedScans($business, 'window', 27, 40);
        $this->seedScans($business, 'window', 9, 5);
        $this->seedScans($business, 'table', 6, 3);

        $counts = $this->coaching()->windowedScanCounts($business, 21);
        $this->assertSame(9, $counts['window'], 'Only the 9 scans inside the common window count.');
        $this->assertSame(6, $counts['table']);
        $this->assertSame(0, $counts['counter']);
    }

    public function test_lifetime_36_vs_6_resolves_to_silence(): void
    {
        // The banned comparison (36 lifetime vs 6 lifetime) must
        // never fire: over the common 21-day window the pair is
        // 9 vs 6, which fails the floor, and v1 renders only at
        // W = 28 in any case (COACH-03).
        $business = $this->businessWithAges(['counter' => 92, 'window' => 92, 'table' => 21, 'receipt' => 92]);
        $this->seedScans($business, 'window', 27, 40);
        $this->seedScans($business, 'window', 9, 5);
        $this->seedScans($business, 'table', 6, 3);

        $this->assertNull($this->coaching()->assess($business));
        $this->assertNull($this->coaching()->cardFor($business));
    }

    public function test_counts_bucket_by_placement_metadata_never_by_code_id(): void
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $codes = QrCode::ensurePlacements($business);

        // A scan stamped with the window placement counts for the
        // window even when a qr_code_id in its metadata names the
        // counter code: counting keys on placement, so a replace
        // never erases history the placement earned.
        Carbon::setTestNow($this->now->copy()->subDays(2));
        ReferralEvent::record('scan', 'customer', $business, null, null, null,
            ['placement' => 'window', 'qr_code_id' => $codes['counter']->id]);
        // A legacy scan with no placement metadata is the counter's.
        ReferralEvent::record('scan', 'customer', $business, null, null, null, []);
        Carbon::setTestNow($this->now);

        $counts = $this->coaching()->windowedScanCounts($business, 28);
        $this->assertSame(1, $counts['window']);
        $this->assertSame(1, $counts['counter']);
    }

    public function test_window_boundary_is_inclusive_at_28_days(): void
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScanAt($business, 'window', $this->now->copy()->subDays(28));
        $this->seedScanAt($business, 'window', $this->now->copy()->subDays(28)->subHour());

        $counts = $this->coaching()->windowedScanCounts($business, 28);
        $this->assertSame(1, $counts['window']);
    }

    // ---------- The floor (Section 1.5): each conjunct alone ----------

    public function test_worked_example_two_fires_with_the_counter_move(): void
    {
        $candidate = $this->coaching()->assess($this->firingBusiness());

        $this->assertNotNull($candidate);
        $this->assertSame('window', $candidate['best']);
        $this->assertSame('counter', $candidate['worst']);
        $this->assertSame(12, $candidate['best_scans']);
        $this->assertSame(2, $candidate['worst_scans']);
        $this->assertSame(10, $candidate['gap']);
        $this->assertSame(28, $candidate['window_days']);
        $this->assertSame('receipt_cards', $candidate['move_key']);
        $this->assertSame('Print a sheet of cards', $candidate['action_label']);
    }

    public function test_floor_f1_flow_inactive_in_each_sub_state(): void
    {
        // No friend reward text.
        $noText = $this->firingBusiness();
        $noText->forceFill(['friend_reward_text' => null])->save();
        $this->assertNull($this->coaching()->assess($noText->fresh()));

        // Allowance exhausted with no card (ACTIVATION
        // REDESIGN, 2026-10-30): the sub-state this block used
        // to pin — billing_status 'setup_incomplete' — is no
        // longer an activation input at all (billing_status
        // left the gate), so a cardless business in that
        // billing state with allowance left is LIVE and
        // coaching may render. The inactive billing-flavored
        // sub-state that remains is the allowance pause: five
        // counted visits on the ledger, no card.
        $exhausted = $this->firingBusiness();
        $exhReferrer = World::customer('+12125550701', 'Reg', 'Ular');
        [$exhMembership] = World::membership($exhausted, $exhReferrer);
        for ($i = 0; $i < 5; $i++) {
            $exhFriend = World::customer('+1212555071'.(string) $i, 'Fri', 'End');
            [$exhReferral] = World::referral($exhMembership, $exhFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $exhausted->id,
                'referral_id' => $exhReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
        $this->assertNull($this->coaching()->assess($exhausted->fresh()));

        // Business not active.
        $closed = $this->firingBusiness();
        $closed->forceFill(['status' => 'closed', 'closed_at' => $this->now])->save();
        $this->assertNull($this->coaching()->assess($closed->fresh()));

        // Past due with the grace window closed (episode 10 days
        // ago, grace is 7): the promised pause holds, no card.
        $paused = $this->firingBusiness();
        $paused->forceFill(['billing_status' => 'past_due'])->save();
        Carbon::setTestNow($this->now->copy()->subDays(10));
        AuditLog::write('billing.past_due', null, [], $paused);
        Carbon::setTestNow($this->now);
        $this->assertNull($this->coaching()->assess($paused->fresh()));

        // Past due INSIDE grace (episode 2 days ago): the flow is
        // live, so coaching may render.
        $grace = $this->firingBusiness();
        $grace->forceFill(['billing_status' => 'past_due'])->save();
        Carbon::setTestNow($this->now->copy()->subDays(2));
        AuditLog::write('billing.past_due', null, [], $grace);
        Carbon::setTestNow($this->now);
        $this->assertNotNull($this->coaching()->assess($grace->fresh()));
    }

    public function test_floor_f2_needs_two_eligible_codes(): void
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 5, 'table' => 5, 'receipt' => 5]);
        $this->seedScans($business, 'counter', 12, 2);
        $this->seedScans($business, 'window', 1, 2);

        $this->assertNull($this->coaching()->assess($business));
    }

    public function test_floor_f4_best_must_reach_eight(): void
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 7, 5);
        $this->seedScans($business, 'counter', 1, 5);
        // Gap 6 and ratio 7x both hold; only F4 fails.
        $this->assertNull($this->coaching()->assess($business));
    }

    public function test_floor_f5_zero_is_not_coaching(): void
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        // Counter sits at 0 in the window: SA-06's state, never a
        // coaching pair, even with an infinite ratio.
        $this->assertNull($this->coaching()->assess($business));
    }

    public function test_floor_f6_gap_and_ratio_are_both_required(): void
    {
        // Ratio holds (10/3 = 3.3x) but the gap is 7... use the
        // pure ratio failure: gap 7 holds, ratio 12/5 = 2.4 fails.
        $ratioFail = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($ratioFail, 'window', 12, 5);
        $this->seedScans($ratioFail, 'counter', 5, 5);
        $this->seedScans($ratioFail, 'table', 6, 5);
        $this->seedScans($ratioFail, 'receipt', 6, 5);
        $this->assertNull($this->coaching()->assess($ratioFail));

        // Gap failure: best 8, worst 3 -> gap 5 (and ratio 2.67);
        // the pair is close, silence is the truthful render.
        $gapFail = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($gapFail, 'window', 8, 5);
        $this->seedScans($gapFail, 'counter', 3, 5);
        $this->seedScans($gapFail, 'table', 4, 5);
        $this->seedScans($gapFail, 'receipt', 4, 5);
        $this->assertNull($this->coaching()->assess($gapFail));
    }

    public function test_ties_for_best_or_worst_are_silence(): void
    {
        $tieBest = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($tieBest, 'window', 12, 5);
        $this->seedScans($tieBest, 'table', 12, 5);
        $this->seedScans($tieBest, 'counter', 2, 5);
        $this->seedScans($tieBest, 'receipt', 2, 5);
        $this->assertNull($this->coaching()->assess($tieBest));

        $tieWorst = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($tieWorst, 'window', 12, 5);
        $this->seedScans($tieWorst, 'counter', 2, 5);
        $this->seedScans($tieWorst, 'table', 2, 5);
        $this->seedScans($tieWorst, 'receipt', 5, 5);
        $this->assertNull($this->coaching()->assess($tieWorst));
    }

    public function test_v1_renders_only_at_window_28_even_with_a_strong_gap(): void
    {
        // COACH-03: both codes 21 days old, a 12 vs 1 split. The
        // data floor (W >= 14) is met, but v1 has exactly one true
        // window phrase, so the card waits for W = 28.
        $business = $this->businessWithAges(['counter' => 21, 'window' => 21, 'table' => 21, 'receipt' => 21]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'counter', 1, 5);

        $this->assertSame(21, $this->coaching()->windowDaysFor($business));
        $this->assertNull($this->coaching()->assess($business));
    }

    public function test_selection_uses_eligible_codes_only_and_never_names_the_middle(): void
    {
        // The counter is 10 days old (ineligible) with a pile of
        // scans: it is neither benchmark nor coached. Window 12
        // vs table 2 fires; receipt (5) is the unnamed middle.
        $business = $this->businessWithAges(['counter' => 10, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'counter', 40, 3);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'table', 2, 5);
        $this->seedScans($business, 'receipt', 5, 5);

        $candidate = $this->coaching()->assess($business);
        $this->assertNotNull($candidate);
        $this->assertSame('window', $candidate['best']);
        $this->assertSame('table', $candidate['worst']);
        $this->assertSame('counter_poster', $candidate['move_key']);
    }

    public function test_a_zero_minimum_blocks_even_when_another_pair_would_qualify(): void
    {
        // Section 4.1: worst is THE unique minimum. Receipt at 0
        // makes the (best, worst) pair fail F5 and the decision
        // stops; it does not fall through to the table.
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'counter', 30, 5);
        $this->seedScans($business, 'table', 2, 5);
        $this->seedScans($business, 'window', 12, 5);
        // receipt: 0 in window.
        $this->assertNull($this->coaching()->assess($business));
    }

    // ---------- Move table (Section 4.2) ----------

    public function test_move_table_is_keyed_by_the_losing_placement_only(): void
    {
        $expectations = [
            // worst => [move_key, move sentence fragment, action label]
            'counter' => ['receipt_cards', 'Try a stack of receipt cards by the register, handed out with every receipt.', 'Print a sheet of cards'],
            'window' => ['counter_poster', 'Try a counter poster by the register, where every paying customer stands.', 'Print the counter poster'],
            'table' => ['counter_poster', 'Try a counter poster by the register, where every paying customer stands.', 'Print the counter poster'],
            'receipt' => ['receipt_habit', 'Try keeping the card stack right by the register, and hand one out with every receipt.', 'Print a sheet of cards'],
        ];

        foreach ($expectations as $worst => [$moveKey, $moveSentence, $actionLabel]) {
            $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
            $best = $worst === 'window' ? 'counter' : 'window';
            $this->seedScans($business, $best, 12, 5);
            $this->seedScans($business, $worst, 2, 5);
            foreach (['counter', 'window', 'table', 'receipt'] as $placement) {
                if ($placement !== $best && $placement !== $worst) {
                    $this->seedScans($business, $placement, 5, 5);
                }
            }

            $candidate = $this->coaching()->assess($business);
            $this->assertNotNull($candidate, "Pair with worst={$worst} should fire.");
            $this->assertSame($worst, $candidate['worst']);
            $this->assertSame($moveKey, $candidate['move_key']);
            $this->assertSame($moveSentence, $candidate['move_sentence']);
            $this->assertSame($actionLabel, $candidate['action_label']);
        }
    }

    // ---------- Disjointness with SA-06 (Section 1.6) ----------

    public function test_last_scan_29_days_ago_is_neither_coached_nor_stale(): void
    {
        // Boundary ruling: the loser's last scan 29 days ago is
        // outside the 28-day window (0 in W, F5 fails, no card)
        // and inside SA-06's 30 days (not stale). It renders its
        // plain row for a day; SA-06 owns it from day 30.
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'counter', 2, 29);
        $this->seedScans($business, 'table', 5, 5);
        $this->seedScans($business, 'receipt', 5, 5);

        $this->assertNull($this->coaching()->assess($business));
        $this->assertFalse($this->coaching()->placementIsStale($business, 'counter'));
    }

    public function test_a_coachable_loser_is_never_stale_across_the_boundary_ages(): void
    {
        // Property pin over the boundary: whenever a pair fires,
        // the loser's last scan is inside the 28-day window, so
        // its silence is at most 28 days and SA-06 cannot hold.
        foreach ([1, 7, 14, 21, 27, 28] as $lastScanDaysAgo) {
            $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
            $this->seedScans($business, 'window', 12, 5);
            $this->seedScans($business, 'counter', 2, $lastScanDaysAgo);
            $this->seedScans($business, 'table', 5, 5);
            $this->seedScans($business, 'receipt', 5, 5);

            $this->assertNotNull($this->coaching()->assess($business), "Last scan {$lastScanDaysAgo}d ago should still coach.");
            $this->assertFalse($this->coaching()->placementIsStale($business, 'counter'));
            $this->assertFalse($this->coaching()->placementIsStale($business, 'window'));
        }

        foreach ([29, 30, 31, 45] as $lastScanDaysAgo) {
            $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
            $this->seedScans($business, 'window', 12, 5);
            $this->seedScans($business, 'counter', 2, $lastScanDaysAgo);
            $this->seedScans($business, 'table', 5, 5);
            $this->seedScans($business, 'receipt', 5, 5);

            $this->assertNull($this->coaching()->assess($business), "Last scan {$lastScanDaysAgo}d ago must not coach.");
        }
    }

    // ---------- Suppression (Section 5.3) ----------

    public function test_dismissal_hides_until_the_gap_grows_by_half(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $candidate = $service->assess($business);
        $service->recordImpression($business, $candidate);
        $service->recordDismissal($business, $candidate);

        $this->assertNull($service->cardFor($business), 'Dismissed fingerprint stays hidden.');

        // Gap 10 -> 14: below ceil(1.5 x 10) = 15, still hidden.
        $this->seedScans($business, 'window', 4, 1);
        Carbon::setTestNow($this->now->copy()->addDays(2));
        $this->assertNull($service->cardFor($business->fresh()));

        // Gap 10 -> 15: the fingerprint changed materially, the
        // card resurfaces (same pair, same move).
        $this->seedScans($business, 'window', 1, 0);
        $resurfaced = $service->cardFor($business->fresh());
        $this->assertNotNull($resurfaced);
        $this->assertSame(15, $resurfaced['gap']);
        Carbon::setTestNow($this->now);
    }

    public function test_dismissal_of_one_pair_does_not_hide_a_renamed_states_numbers_forever(): void
    {
        // Time alone never resurfaces a dismissed fingerprint:
        // 40 days later, same pair, same gap, still hidden.
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $candidate = $service->assess($business);
        $service->recordDismissal($business, $candidate);

        Carbon::setTestNow($this->now->copy()->addDays(40));
        // Keep the window counts alive at the new "now": the
        // original scans aged out, so re-seed the same shape.
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'counter', 2);
        $this->assertNull($service->cardFor($business->fresh()));
        Carbon::setTestNow($this->now);
    }

    private function seedScansAtNow(Business $business, string $placement, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ReferralEvent::record('scan', 'customer', $business, null, null, null, ['placement' => $placement]);
        }
    }

    public function test_acted_print_suppresses_for_28_days_then_releases(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $candidate = $service->assess($business);
        $service->recordImpression($business, $candidate);

        // The owner acts 2 days later: prints the suggested
        // artifact (the receipt sheet, variant=receipt) through
        // the shipped poster path's audit record.
        Carbon::setTestNow($this->now->copy()->addDays(2));
        AuditLog::write('qr.poster_downloaded', null, ['variant' => 'receipt'], $business);
        $this->assertNull($service->cardFor($business->fresh()), 'Acted fingerprint suppresses.');

        // Still suppressed 27 days after the print.
        Carbon::setTestNow($this->now->copy()->addDays(29));
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'counter', 2);
        $this->seedScansAtNow($business, 'table', 6);
        $this->seedScansAtNow($business, 'receipt', 6);
        $this->assertNull($service->cardFor($business->fresh()));

        // 29 days after the print the suppression has run out;
        // the same live pair may return.
        Carbon::setTestNow($this->now->copy()->addDays(31));
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'counter', 2);
        $this->seedScansAtNow($business, 'table', 6);
        $this->seedScansAtNow($business, 'receipt', 6);
        $this->assertNotNull($service->cardFor($business->fresh()));
        Carbon::setTestNow($this->now);
    }

    public function test_a_print_of_the_coached_spots_own_artifact_also_counts_as_acted(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $service->recordImpression($business, $service->assess($business));

        // Worst is counter; printing the counter poster itself
        // (variant=counter) is an acted signal per Section 7.1.
        Carbon::setTestNow($this->now->copy()->addDays(1));
        AuditLog::write('qr.poster_downloaded', null, ['variant' => 'counter'], $business);
        $this->assertNull($service->cardFor($business->fresh()));
        Carbon::setTestNow($this->now);
    }

    public function test_an_unrelated_print_does_not_suppress(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $service->recordImpression($business, $service->assess($business));

        // Printing the table tent is neither the suggested
        // artifact nor the coached spot's own: no suppression.
        Carbon::setTestNow($this->now->copy()->addDays(1));
        AuditLog::write('qr.poster_downloaded', null, ['variant' => 'tent'], $business);
        $this->assertNotNull($service->cardFor($business->fresh()));
        Carbon::setTestNow($this->now);
    }

    public function test_global_cooldown_blocks_a_new_pair_for_7_days_after_a_dismissal(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $service->recordDismissal($business, $service->assess($business));

        // Next day the data names a different pair (table now
        // trails window): the cooldown keeps the page quiet.
        Carbon::setTestNow($this->now->copy()->addDays(1));
        $this->seedScans($business, 'table', 0, 0); // no-op, clarity anchor
        // Rebuild the shape at the new now: window still best,
        // table becomes the unique minimum.
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'table', 1);
        $this->seedScansAtNow($business, 'counter', 6);
        $this->seedScansAtNow($business, 'receipt', 6);
        $fresh = $service->assess($business->fresh());
        $this->assertNotNull($fresh, 'A new pair qualifies on the data.');
        $this->assertSame('table', $fresh['worst']);
        $this->assertNull($service->cardFor($business->fresh()), 'Cooldown suppresses the new pair.');

        // Eight days after the dismissal the cooldown has run.
        Carbon::setTestNow($this->now->copy()->addDays(8));
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'table', 1);
        $this->seedScansAtNow($business, 'counter', 6);
        $this->seedScansAtNow($business, 'receipt', 6);
        $this->assertNotNull($service->cardFor($business->fresh()));
        Carbon::setTestNow($this->now);
    }

    public function test_three_dismissals_in_a_row_snooze_all_coaching_for_90_days(): void
    {
        $service = $this->coaching();
        $business = $this->firingBusiness();

        // Dismissal 1: window over counter (gap 10).
        $service->recordDismissal($business, $service->assess($business));

        // Dismissal 2 (day 10): a different pair — table trails.
        Carbon::setTestNow($this->now->copy()->addDays(10));
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'table', 1);
        $this->seedScansAtNow($business, 'counter', 6);
        $this->seedScansAtNow($business, 'receipt', 6);
        $second = $service->assess($business->fresh());
        $this->assertSame('table', $second['worst']);
        $service->recordDismissal($business->fresh(), $second);

        // Dismissal 3 (day 20): receipt trails.
        Carbon::setTestNow($this->now->copy()->addDays(20));
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'receipt', 1);
        $this->seedScansAtNow($business, 'counter', 6);
        $this->seedScansAtNow($business, 'table', 8);
        $third = $service->assess($business->fresh());
        $this->assertSame('receipt', $third['worst']);
        $service->recordDismissal($business->fresh(), $third);

        // A brand-new pair at day 30 (receipt best, table
        // worst: never dismissed, so neither the dismissal leg
        // nor the 7-day cooldown can hold it) is inside the
        // 90-day snooze, so the snooze alone holds it.
        Carbon::setTestNow($this->now->copy()->addDays(30));
        $this->seedScansAtNow($business, 'receipt', 40);
        $fresh = $service->assess($business->fresh());
        $this->assertNotNull($fresh);
        $this->assertSame('receipt', $fresh['best']);
        $this->assertSame('table', $fresh['worst']);
        $this->assertNull($service->cardFor($business->fresh()), 'Snoozed: no coaching at all.');

        // At day 111 (91 days after the third dismissal) the
        // snooze has run out.
        Carbon::setTestNow($this->now->copy()->addDays(111));
        $this->seedScansAtNow($business, 'receipt', 12);
        $this->seedScansAtNow($business, 'table', 1);
        $this->seedScansAtNow($business, 'counter', 6);
        $this->seedScansAtNow($business, 'window', 6);
        $this->assertNotNull($service->cardFor($business->fresh()));
        Carbon::setTestNow($this->now);
    }

    public function test_an_acted_event_breaks_the_dismissal_streak(): void
    {
        $service = $this->coaching();
        $business = $this->firingBusiness();

        $service->recordImpression($business, $service->assess($business));
        $service->recordDismissal($business, $service->assess($business));

        // An acted print after the dismissal resets the streak,
        // so two further dismissals do not reach three in a row.
        Carbon::setTestNow($this->now->copy()->addDays(1));
        AuditLog::write('qr.poster_downloaded', null, ['variant' => 'receipt'], $business);

        Carbon::setTestNow($this->now->copy()->addDays(10));
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'table', 1);
        $this->seedScansAtNow($business, 'counter', 6);
        $this->seedScansAtNow($business, 'receipt', 6);
        $service->recordDismissal($business->fresh(), $service->assess($business->fresh()));

        Carbon::setTestNow($this->now->copy()->addDays(20));
        $this->seedScansAtNow($business, 'window', 12);
        $this->seedScansAtNow($business, 'receipt', 1);
        $this->seedScansAtNow($business, 'counter', 6);
        $this->seedScansAtNow($business, 'table', 8);
        $service->recordDismissal($business->fresh(), $service->assess($business->fresh()));

        // Only two in a row since the act: no snooze. The day-30
        // pair (receipt best, table worst) was never dismissed
        // and the last dismissal is 10 days old, so nothing else
        // can hold it either.
        Carbon::setTestNow($this->now->copy()->addDays(30));
        $this->seedScansAtNow($business, 'receipt', 40);
        $this->assertNotNull($service->cardFor($business->fresh()));
        Carbon::setTestNow($this->now);
    }

    // ---------- Measurement (Section 7.1) ----------

    public function test_impressions_are_recorded_once_per_fingerprint_per_day(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $candidate = $service->assess($business);

        $service->recordImpression($business, $candidate);
        $service->recordImpression($business, $candidate);
        $this->assertSame(1, PlacementCoachingEvent::query()
            ->where('business_id', $business->id)->where('kind', 'impression')->count());

        $row = PlacementCoachingEvent::query()->where('business_id', $business->id)->sole();
        $this->assertSame($candidate['fingerprint'], $row->fingerprint);
        $this->assertSame(12, $row->best_scans);
        $this->assertSame(2, $row->worst_scans);
        $this->assertSame(28, $row->window_days);

        Carbon::setTestNow($this->now->copy()->addDay());
        $service->recordImpression($business, $candidate);
        $this->assertSame(2, PlacementCoachingEvent::query()
            ->where('business_id', $business->id)->where('kind', 'impression')->count());
        Carbon::setTestNow($this->now);
    }

    public function test_acted_within_14_days_derivation(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $candidate = $service->assess($business);
        $service->recordImpression($business, $candidate);
        $impression = PlacementCoachingEvent::query()->where('business_id', $business->id)->sole();

        $this->assertFalse($service->actedWithin14Days($impression));

        // A qualifying print 10 days after the impression counts.
        Carbon::setTestNow($this->now->copy()->addDays(10));
        AuditLog::write('qr.poster_downloaded', null, ['variant' => 'receipt'], $business);
        $this->assertTrue($service->actedWithin14Days($impression->fresh()));

        // A print 15 days after a fresh impression does not.
        $business2 = $this->firingBusiness();
        $service->recordImpression($business2, $service->assess($business2));
        $impression2 = PlacementCoachingEvent::query()->where('business_id', $business2->id)->sole();
        Carbon::setTestNow($this->now->copy()->addDays(25));
        AuditLog::write('qr.poster_downloaded', null, ['variant' => 'receipt'], $business2);
        $this->assertFalse($service->actedWithin14Days($impression2->fresh()));
        Carbon::setTestNow($this->now);
    }

    public function test_replacing_the_coached_code_is_an_acted_signal(): void
    {
        $business = $this->firingBusiness();
        $service = $this->coaching();
        $candidate = $service->assess($business);
        $service->recordImpression($business, $candidate);
        $impression = PlacementCoachingEvent::query()->where('business_id', $business->id)->sole();

        Carbon::setTestNow($this->now->copy()->addDays(3));
        QrCode::replacePlacement($business, 'counter');

        $this->assertTrue($service->actedWithin14Days($impression->fresh()));
        Carbon::setTestNow($this->now);
    }
}
