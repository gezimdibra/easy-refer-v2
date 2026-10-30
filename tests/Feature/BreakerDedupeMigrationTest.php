<?php

namespace Tests\Feature;

use App\Models\BillingEvent;
use App\Models\Referral;
use App\Models\ReferralEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\World;
use Tests\TestCase;

/**
 * BREAKER DEDUPE CLOSE — migration 000039 dirty-data pins
 * (two-key Findings 1 + 2 on hotfix/breaker-critical).
 *
 * The original 000039 dropped the old active_pair_key unique
 * first and built the new counted_pair_key unique last, with no
 * census: a database carrying even one duplicate counted pair —
 * exactly the rows the live D-1 defect minted — crashed the
 * index build and was left with NO pair uniqueness at all.
 *
 * These pins recreate the PRE-000039 schema (000013's
 * pending-only active_pair_key) on a migrated test database,
 * seed duplicate counted pairs in the shapes the old schema
 * admitted, and run the migration file's up() by name:
 *
 *   vendor/bin/phpunit --filter BreakerDedupeMigrationTest
 *
 * Resolution semantics under pin (see the migration header):
 * the survivor is the governing row (redeemed > pending >
 * voided; earliest within a state); pending/voided losers are
 * expired with an audit event; a redeemed loser keeps its
 * state, reward, and BillingEvent untouched and is only
 * stamped pair_superseded_at for a separate operational money
 * decision.
 */
class BreakerDedupeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Put referrals back to the pre-000039 (migration 000013) schema. */
    private function restorePreMigrationSchema(): void
    {
        Schema::table('referrals', function ($table) {
            $table->dropUnique(['counted_pair_key']);
        });
        DB::statement('ALTER TABLE referrals DROP COLUMN counted_pair_key');
        DB::statement('ALTER TABLE referrals DROP COLUMN pair_superseded_at');

        $expression = "CASE WHEN state = 'pending_redemption' THEN business_id || ':' || referred_customer_id ELSE NULL END";
        DB::statement("ALTER TABLE referrals ADD COLUMN active_pair_key VARCHAR(64) GENERATED ALWAYS AS ({$expression}) VIRTUAL");
        Schema::table('referrals', function ($table) {
            $table->unique('active_pair_key');
        });
    }

    private function runMigration039(): object
    {
        $migration = require base_path('database/migrations/0001_01_01_000039_widen_referral_pair_key_to_counted_states.php');
        $migration->up();

        return $migration;
    }

    private function indexExists(string $name): bool
    {
        return DB::selectOne(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'referrals' AND name = ?",
            [$name]
        ) !== null;
    }

    /**
     * Seed the three duplicate shapes the old schema admitted.
     *
     * @return array<string, mixed>
     */
    private function seedDuplicatePairs(): array
    {
        $business = World::business();
        [$referrer] = World::membership($business, World::customer('+12125550300', 'Sam', 'Reed'));

        // Group A: redeemed (the counted visit) + a later pending
        // duplicate. Survivor: the redeemed row.
        $friendA = World::customer('+12125550301', 'Jamie', 'Aye');
        [$aRedeemed] = World::referral($referrer, $friendA, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(3)]);
        $aRedeemed->forceFill(['created_at' => now()->subDays(3), 'redeemed_at' => now()->subDays(3)])->saveQuietly();
        [$aReward] = World::reward($referrer, $aRedeemed);
        BillingEvent::create(['business_id' => $business->id, 'referral_id' => $aRedeemed->id, 'amount_cents' => 200, 'coverage' => 'billable']);
        [$aPending] = World::referral($referrer, $friendA);
        $aPending->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        // Group B: voided (denied) + a later pending duplicate.
        // Survivor per the governing priority: the live pending
        // row; the voided loser is expired.
        $friendB = World::customer('+12125550302', 'Jamie', 'Bee');
        [$bVoided] = World::referral($referrer, $friendB, ['state' => Referral::STATE_VOIDED]);
        [$bPending] = World::referral($referrer, $friendB);

        // Group C: TWO redeemed rows — a money duplicate. The
        // earliest redeemed row survives; the later one keeps
        // its state, reward, and BillingEvent and is only
        // stamped superseded.
        $friendC = World::customer('+12125550303', 'Jamie', 'Cee');
        [$cFirst] = World::referral($referrer, $friendC, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(4)]);
        $cFirst->forceFill(['created_at' => now()->subDays(4), 'redeemed_at' => now()->subDays(4)])->saveQuietly();
        World::reward($referrer, $cFirst);
        BillingEvent::create(['business_id' => $business->id, 'referral_id' => $cFirst->id, 'amount_cents' => 200, 'coverage' => 'billable']);
        [$cSecond] = World::referral($referrer, $friendC, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2)]);
        $cSecond->forceFill(['created_at' => now()->subDays(2), 'redeemed_at' => now()->subDays(2)])->saveQuietly();
        [$cSecondReward] = World::reward($referrer, $cSecond);
        $cSecondEvent = BillingEvent::create(['business_id' => $business->id, 'referral_id' => $cSecond->id, 'amount_cents' => 200, 'coverage' => 'billable']);

        // Control: a single redeemed pair, not a duplicate.
        $friendD = World::customer('+12125550304', 'Jamie', 'Dee');
        [$dSingle] = World::referral($referrer, $friendD, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay()]);
        World::reward($referrer, $dSingle);
        BillingEvent::create(['business_id' => $business->id, 'referral_id' => $dSingle->id, 'amount_cents' => 200, 'coverage' => 'billable']);

        return compact('business', 'referrer', 'aRedeemed', 'aReward', 'aPending', 'bVoided', 'bPending', 'cFirst', 'cSecond', 'cSecondReward', 'cSecondEvent', 'dSingle');
    }

    public function test_seeded_duplicate_pairs_migrate_cleanly_with_deterministic_survivors(): void
    {
        $this->restorePreMigrationSchema();
        $s = $this->seedDuplicatePairs();
        $billingBefore = (int) BillingEvent::query()->where('business_id', $s['business']->id)->sum('amount_cents');
        $this->assertSame(800, $billingBefore);

        $this->runMigration039();

        // The new schema is in place; the old one is gone.
        $this->assertTrue(Schema::hasColumn('referrals', 'counted_pair_key'));
        $this->assertTrue(Schema::hasColumn('referrals', 'pair_superseded_at'));
        $this->assertFalse(Schema::hasColumn('referrals', 'active_pair_key'));
        $this->assertTrue($this->indexExists('referrals_counted_pair_key_unique'));
        $this->assertFalse($this->indexExists('referrals_active_pair_key_unique'));

        // Group A: redeemed survives untouched; pending loser
        // expired + stamped + audited.
        $this->assertSame(Referral::STATE_REDEEMED, $s['aRedeemed']->fresh()->state);
        $this->assertNull($s['aRedeemed']->fresh()->pair_superseded_at);
        $this->assertSame(Referral::STATE_EXPIRED, $s['aPending']->fresh()->state);
        $this->assertNotNull($s['aPending']->fresh()->pair_superseded_at);
        $this->assertSame(1, ReferralEvent::query()->where('event_type', 'dedupe_duplicate_expired')->where('referral_id', $s['aPending']->id)->count());

        // Group B: the live pending row survives; the voided
        // loser is expired + stamped + audited.
        $this->assertSame(Referral::STATE_PENDING, $s['bPending']->fresh()->state);
        $this->assertNull($s['bPending']->fresh()->pair_superseded_at);
        $this->assertSame(Referral::STATE_EXPIRED, $s['bVoided']->fresh()->state);
        $this->assertNotNull($s['bVoided']->fresh()->pair_superseded_at);

        // Group C (money duplicate): earliest redeemed survives;
        // the later redeemed row is STILL redeemed, its reward
        // and BillingEvent byte-identical in state — the
        // migration only stamped it and audited the flag for a
        // separate operational decision.
        $this->assertSame(Referral::STATE_REDEEMED, $s['cFirst']->fresh()->state);
        $this->assertNull($s['cFirst']->fresh()->pair_superseded_at);
        $this->assertSame(Referral::STATE_REDEEMED, $s['cSecond']->fresh()->state);
        $this->assertNotNull($s['cSecond']->fresh()->pair_superseded_at);
        $this->assertSame($s['cSecondReward']->state, $s['cSecondReward']->fresh()->state);
        $this->assertSame(200, (int) $s['cSecondEvent']->fresh()->amount_cents);
        $this->assertSame('billable', $s['cSecondEvent']->fresh()->coverage);
        $superseded = ReferralEvent::query()->where('event_type', 'dedupe_duplicate_superseded')->where('referral_id', $s['cSecond']->id)->first();
        $this->assertNotNull($superseded);
        $this->assertSame('manual_review_required', $superseded->metadata['money_resolution'] ?? null);
        $this->assertSame($s['cFirst']->id, (int) ($superseded->metadata['survivor_referral_id'] ?? 0));

        // Control pair + total billing history: untouched.
        $this->assertNull($s['dSingle']->fresh()->pair_superseded_at);
        $this->assertSame($billingBefore, (int) BillingEvent::query()->where('business_id', $s['business']->id)->sum('amount_cents'), 'the migration rewrites no billing history');
    }

    public function test_post_migration_the_index_exists_and_rejects_a_fresh_duplicate_insert(): void
    {
        $this->restorePreMigrationSchema();
        $s = $this->seedDuplicatePairs();

        $this->runMigration039();

        $this->assertTrue($this->indexExists('referrals_counted_pair_key_unique'));

        // Friend A already counts (the redeemed survivor): even
        // bypassing every service, the database refuses a second
        // pending referral for the pair.
        $friendA = $s['aRedeemed']->referredCustomer;
        $this->expectException(QueryException::class);
        World::referral($s['referrer'], $friendA);
    }

    public function test_migration_run_twice_is_safe_and_the_resolution_is_idempotent(): void
    {
        $this->restorePreMigrationSchema();
        $s = $this->seedDuplicatePairs();

        $migration = $this->runMigration039();
        $expiredEvents = ReferralEvent::query()->where('event_type', 'dedupe_duplicate_expired')->count();
        $supersededEvents = ReferralEvent::query()->where('event_type', 'dedupe_duplicate_superseded')->count();
        $this->assertGreaterThan(0, $expiredEvents + $supersededEvents);

        // A plain re-run (the retry after any failed first run)
        // completes, changes nothing, and writes no second
        // resolution.
        $migration->up();

        $this->assertTrue($this->indexExists('referrals_counted_pair_key_unique'));
        $this->assertSame($expiredEvents, ReferralEvent::query()->where('event_type', 'dedupe_duplicate_expired')->count());
        $this->assertSame($supersededEvents, ReferralEvent::query()->where('event_type', 'dedupe_duplicate_superseded')->count());
        $this->assertSame(Referral::STATE_EXPIRED, $s['aPending']->fresh()->state);
        $this->assertSame(Referral::STATE_REDEEMED, $s['cSecond']->fresh()->state);
    }

    public function test_migration_resumes_from_a_partial_run_with_the_column_present_but_no_index(): void
    {
        // The state a failed run can leave behind: the stamp and
        // the generated column exist, no counted unique yet, and
        // the OLD index is still in place (the new ordering
        // never drops it early). Re-running must finish the job
        // with no manual DDL repair.
        $this->restorePreMigrationSchema();
        $s = $this->seedDuplicatePairs();

        Schema::table('referrals', function ($table) {
            $table->timestamp('pair_superseded_at')->nullable();
        });
        $expression = "CASE WHEN pair_superseded_at IS NULL AND state IN ('pending_redemption', 'redeemed', 'voided') THEN business_id || ':' || referred_customer_id ELSE NULL END";
        DB::statement("ALTER TABLE referrals ADD COLUMN counted_pair_key VARCHAR(64) GENERATED ALWAYS AS ({$expression}) VIRTUAL");
        $this->assertTrue($this->indexExists('referrals_active_pair_key_unique'));
        $this->assertFalse($this->indexExists('referrals_counted_pair_key_unique'));

        $this->runMigration039();

        $this->assertTrue($this->indexExists('referrals_counted_pair_key_unique'));
        $this->assertFalse($this->indexExists('referrals_active_pair_key_unique'));
        $this->assertSame(Referral::STATE_EXPIRED, $s['aPending']->fresh()->state);
        $this->assertNotNull($s['cSecond']->fresh()->pair_superseded_at);
    }
}
