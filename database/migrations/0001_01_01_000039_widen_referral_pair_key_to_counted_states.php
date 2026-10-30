<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BREAKER D-1 (breaker-report-2026-10-29): the dedupe guarantee
 * widens to the states in which a pair is spoken for.
 *
 * Migration 000013's generated column active_pair_key held the
 * (business, friend) pair ONLY while pending_redemption, so the
 * moment a referral redeemed, the pair was free at every layer
 * and a second full claim minted a second referral, a second
 * referrer reward, and a second $2.00 billing event — against
 * the documented truth (COMPANY-KNOWLEDGE §3): dedupe per
 * (business, friend); a visit counts once.
 *
 * counted_pair_key replaces it: the pair is present while the
 * referral is pending_redemption (live), redeemed (counted), or
 * voided (owner-denied; deny is terminal for the pair), and NULL
 * when expired (no visit ever counted, so the slot is free and
 * expired history may accumulate) or when the row was stamped
 * pair_superseded_at by the dedupe resolution below. The unique
 * index therefore admits at most one live-or-counted referral
 * per pair, at the data layer, whatever the services do.
 *
 * Numbering: 000038 is F-UP-1's friend-reward snapshot migration
 * on its own train; this takes 000039 so the two compose at merge
 * with no renumber.
 *
 * DIRTY DATA (two-key Findings 1+2, breaker-critical close):
 * rows minted by the live defect can hold a duplicate counted
 * pair (e.g. one redeemed + one pending, or two redeemed, for
 * the same business and friend), and a bare unique build would
 * crash on exactly those rows. This migration therefore runs,
 * IN ORDER:
 *
 *  1. CENSUS + DETERMINISTIC RESOLUTION, before anything is
 *     dropped, while the old active_pair_key unique index is
 *     still in place (the old protection is never absent before
 *     the new one exists). Counted pairs are grouped over
 *     state IN (pending_redemption, redeemed, voided) with no
 *     superseded stamp. For each duplicate group the SURVIVOR
 *     is the row that governs the pair in the services
 *     (EnrollmentService::governingReferral's own priority):
 *     a redeemed row governs over pending, pending over voided;
 *     within a state, the earliest row wins (redeemed_at, then
 *     created_at, then id) — the first visit that counted is
 *     the natural truth, and the billing walk charges in
 *     creation order, so the earliest redeemed row is the one
 *     whose BillingEvent is the counted visit's fee.
 *  2. LOSER handling, by the loser's own state:
 *     - pending / voided losers have no reward and no
 *       BillingEvent (both are minted only at redemption of
 *       THAT row), so they are moved out of a counted state:
 *       state -> expired, pair_superseded_at stamped, and a
 *       dedupe_duplicate_expired audit event recorded. No
 *       money row is touched.
 *     - a redeemed loser DID count and DID bill: that is a
 *       data fact. The migration does NOT change its state,
 *       does NOT touch its reward, and does NOT touch its
 *       BillingEvent (no silent void, exclusion, or refund —
 *       that money resolution is a separate OPERATIONAL
 *       decision for Gate 1, taken through the existing
 *       fraud-denied path with the pair named). The migration
 *       only stamps pair_superseded_at on the row (which
 *       re-keys it out of counted_pair_key so the index can
 *       build) and records a dedupe_duplicate_superseded audit
 *       event carrying money_resolution=manual_review_required,
 *       so every superseded redeemed row is enumerable at
 *       Gate 1 by: state='redeemed' AND pair_superseded_at
 *       IS NOT NULL.
 *  3. A post-resolution re-census: any duplicate group that
 *     somehow remains aborts the migration with the pairs
 *     named, BEFORE the old index is dropped.
 *  4. counted_pair_key is added and its unique index built.
 *  5. ONLY THEN is active_pair_key dropped. Every intermediate
 *     state carries the old protection or the new one, never
 *     neither.
 *
 * RETRY SAFETY: every step is existence-guarded and the
 * resolution is idempotent (resolved losers leave the census:
 * expired rows are not counted, superseded rows are excluded),
 * so a run that failed at any point can simply be re-run after
 * cleanup with no manual DDL repair.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COUNTED_STATES = ['pending_redemption', 'redeemed', 'voided'];

    public function up(): void
    {
        // Step 0: the superseded stamp (guarded; additive only,
        // the old index is untouched and still protecting).
        if (! Schema::hasColumn('referrals', 'pair_superseded_at')) {
            Schema::table('referrals', function ($table) {
                $table->timestamp('pair_superseded_at')->nullable();
            });
        }

        // Steps 1-3: census, resolve, re-census — all before
        // any drop.
        $this->resolveDuplicateCountedPairs();

        $duplicates = $this->duplicateCountedPairs();

        if ($duplicates !== []) {
            throw new \RuntimeException(
                'Migration 000039: duplicate counted (business, friend) pairs remain after resolution: '
                .implode(', ', array_map(fn ($pair) => $pair['business_id'].':'.$pair['referred_customer_id'].' x'.$pair['rows'], $duplicates))
                .'. The old active_pair_key index is still in place; resolve these pairs and re-run.'
            );
        }

        // Step 4: the new generated column + unique index.
        if (! Schema::hasColumn('referrals', 'counted_pair_key')) {
            $expression = DB::connection()->getDriverName() === 'sqlite'
                ? "CASE WHEN pair_superseded_at IS NULL AND state IN ('pending_redemption', 'redeemed', 'voided') THEN business_id || ':' || referred_customer_id ELSE NULL END"
                : "CASE WHEN pair_superseded_at IS NULL AND state IN ('pending_redemption', 'redeemed', 'voided') THEN CONCAT(business_id, ':', referred_customer_id) ELSE NULL END";

            DB::statement("ALTER TABLE referrals ADD COLUMN counted_pair_key VARCHAR(64) GENERATED ALWAYS AS ({$expression}) VIRTUAL");
        }

        if (! $this->indexExists('referrals_counted_pair_key_unique')) {
            Schema::table('referrals', function ($table) {
                $table->unique('counted_pair_key');
            });
        }

        // Step 5: the new protection exists — only now may the
        // old one go.
        if ($this->indexExists('referrals_active_pair_key_unique')) {
            Schema::table('referrals', function ($table) {
                $table->dropUnique(['active_pair_key']);
            });
        }

        if (Schema::hasColumn('referrals', 'active_pair_key')) {
            DB::statement('ALTER TABLE referrals DROP COLUMN active_pair_key');
        }
    }

    public function down(): void
    {
        // Restore the old protection FIRST (column + pending-only
        // unique), then drop the new one: never neither, in
        // either direction. Post-resolution data satisfies the
        // old unique trivially (pending rows are unique per pair
        // by construction; redeemed/voided rows key NULL there).
        // Note: down() restores the SCHEMA only. The resolution's
        // data facts are retained (expired losers stay expired;
        // superseded stamps are dropped with their column) —
        // a rollback must not resurrect a duplicate pending claim
        // or rewrite the billing/reward rows this migration
        // deliberately left alone.
        if (! Schema::hasColumn('referrals', 'active_pair_key')) {
            $expression = DB::connection()->getDriverName() === 'sqlite'
                ? "CASE WHEN state = 'pending_redemption' THEN business_id || ':' || referred_customer_id ELSE NULL END"
                : "CASE WHEN state = 'pending_redemption' THEN CONCAT(business_id, ':', referred_customer_id) ELSE NULL END";

            DB::statement("ALTER TABLE referrals ADD COLUMN active_pair_key VARCHAR(64) GENERATED ALWAYS AS ({$expression}) VIRTUAL");
        }

        if (! $this->indexExists('referrals_active_pair_key_unique')) {
            Schema::table('referrals', function ($table) {
                $table->unique('active_pair_key');
            });
        }

        if ($this->indexExists('referrals_counted_pair_key_unique')) {
            Schema::table('referrals', function ($table) {
                $table->dropUnique(['counted_pair_key']);
            });
        }

        if (Schema::hasColumn('referrals', 'counted_pair_key')) {
            DB::statement('ALTER TABLE referrals DROP COLUMN counted_pair_key');
        }

        if (Schema::hasColumn('referrals', 'pair_superseded_at')) {
            DB::statement('ALTER TABLE referrals DROP COLUMN pair_superseded_at');
        }
    }

    /**
     * Duplicate counted groups: pairs in a counted state with
     * no superseded stamp, holding more than one row.
     *
     * @return list<array{business_id: mixed, referred_customer_id: mixed, rows: int}>
     */
    private function duplicateCountedPairs(): array
    {
        return DB::table('referrals')
            ->select('business_id', 'referred_customer_id')
            ->selectRaw('COUNT(*) as rows')
            ->whereIn('state', self::COUNTED_STATES)
            ->whereNull('pair_superseded_at')
            ->groupBy('business_id', 'referred_customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'business_id' => $row->business_id,
                'referred_customer_id' => $row->referred_customer_id,
                'rows' => (int) $row->rows,
            ])
            ->all();
    }

    /**
     * The deterministic resolution (see the header): one
     * survivor per duplicate group, losers expired (pending /
     * voided) or stamp-only superseded (redeemed), each with
     * its audit event. Idempotent: resolved rows leave the
     * census, so a re-run finds nothing to redo.
     */
    private function resolveDuplicateCountedPairs(): void
    {
        $priority = ['redeemed' => 0, 'pending_redemption' => 1, 'voided' => 2];

        foreach ($this->duplicateCountedPairs() as $pair) {
            $rows = DB::table('referrals')
                ->where('business_id', $pair['business_id'])
                ->where('referred_customer_id', $pair['referred_customer_id'])
                ->whereIn('state', self::COUNTED_STATES)
                ->whereNull('pair_superseded_at')
                ->get()
                ->sort(function ($a, $b) use ($priority) {
                    $byState = $priority[$a->state] <=> $priority[$b->state];

                    if ($byState !== 0) {
                        return $byState;
                    }

                    $byRedeemed = strcmp((string) ($a->redeemed_at ?? '9999'), (string) ($b->redeemed_at ?? '9999'));

                    if ($byRedeemed !== 0) {
                        return $byRedeemed;
                    }

                    $byCreated = strcmp((string) $a->created_at, (string) $b->created_at);

                    return $byCreated !== 0 ? $byCreated : ($a->id <=> $b->id);
                })
                ->values();

            $survivor = $rows->first();

            foreach ($rows->skip(1) as $loser) {
                if ($loser->state === 'redeemed') {
                    // Stamp-only: state, reward, and BillingEvent
                    // are billing history and stay exactly as
                    // they are; the money call is operational.
                    DB::table('referrals')->where('id', $loser->id)->update([
                        'pair_superseded_at' => now(),
                    ]);

                    $this->audit($loser, 'dedupe_duplicate_superseded', $survivor->id, [
                        'money_resolution' => 'manual_review_required',
                        'billing_event_untouched' => true,
                        'reward_untouched' => true,
                    ]);
                } else {
                    // No reward or BillingEvent can exist for a
                    // never-redeemed row: expiring it frees the
                    // pair key and touches no money.
                    DB::table('referrals')->where('id', $loser->id)->update([
                        'state' => 'expired',
                        'pair_superseded_at' => now(),
                    ]);

                    $this->audit($loser, 'dedupe_duplicate_expired', $survivor->id, [
                        'original_state' => $loser->state,
                    ]);
                }
            }
        }
    }

    /** @param array<string, mixed> $extra */
    private function audit(object $loser, string $eventType, int $survivorId, array $extra): void
    {
        DB::table('referral_events')->insert([
            'business_id' => $loser->business_id,
            'referral_id' => $loser->id,
            'reward_id' => null,
            'membership_id' => $loser->referrer_membership_id,
            'event_type' => $eventType,
            'actor' => 'system',
            'metadata' => json_encode(array_merge([
                'migration' => '000039',
                'survivor_referral_id' => $survivorId,
                'business_id' => $loser->business_id,
                'referred_customer_id' => $loser->referred_customer_id,
            ], $extra)),
            'created_at' => now(),
        ]);
    }

    private function indexExists(string $indexName): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return DB::selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'referrals' AND name = ?",
                [$indexName]
            ) !== null;
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['referrals', $indexName]
        )->aggregate > 0;
    }
};
