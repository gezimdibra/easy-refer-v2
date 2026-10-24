<?php

namespace Tests\Feature\Owner;

use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;
use Tests\TestCase;

/**
 * F6 engine-parity regression coverage (hidden_files/redesign/
 * gate3-log-forensics.md).
 *
 * The defect: the owner dashboard's weekly top-referrers query
 * selected memberships.* while grouping by memberships.id over
 * the referrals join. MariaDB runs with ONLY_FULL_GROUP_BY and
 * rejects that shape with error 1055, so every authenticated
 * dashboard load returned 500 in production. The suite runs on
 * SQLite, where the shape is legal, so no test could ever see
 * it: a harness-versus-production gap of the same family as the
 * release 03 entry-order defect.
 *
 * A live MariaDB connection cannot stand in for this coverage
 * here (the harness is SQLite-only), so the coverage is built
 * to be engine-independent instead:
 *
 * 1. A SQL shape guard over every query a real dashboard load
 *    executes: no GROUP BY query may select a star (the 1055
 *    shape), and the top-referrers aggregate must group by the
 *    single key it selects. This test fails against the pre-F6
 *    controller on SQLite, which is exactly the point: the
 *    class is now catchable in CI.
 * 2. Functional coverage of the two-step replacement (aggregate
 *    by referrer, hydrate, order in PHP): the right members,
 *    the right counts, the right order, the created_at
 *    tie-break applied before the limit, and the scope
 *    exclusions (out-of-window, non-redeemed, removed, other
 *    businesses).
 */
class DashboardEngineParityTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 9000;

    public function test_dashboard_never_selects_a_star_under_a_group_by(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, $this->customer('Guard'));
        $this->redeem($membership, 2, now()->subDay());

        $sql = [];
        DB::listen(function ($event) use (&$sql) {
            $sql[] = strtolower($event->sql);
        });

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $grouped = array_values(array_filter(
            $sql,
            fn (string $query) => str_contains($query, 'group by'),
        ));

        // The aggregate must still exist (deleting the feature
        // is not a fix), and it must be the single-key shape.
        $aggregate = array_values(array_filter(
            $grouped,
            fn (string $query) => str_contains($query, 'referrer_membership_id') && str_contains($query, 'count('),
        ));
        $this->assertNotEmpty($aggregate, 'The dashboard no longer runs the top-referrers aggregate.');

        foreach ($grouped as $query) {
            $selectClause = substr($query, 0, strpos($query, ' from '));
            $this->assertStringNotContainsString(
                '*',
                $selectClause,
                'A GROUP BY query selects a star (the ONLY_FULL_GROUP_BY 1055 shape): '.$query,
            );
        }

        // Grouping keys of the aggregate: exactly the one
        // selected key, nothing joined in from the other table.
        $tail = substr($aggregate[0], strpos($aggregate[0], 'group by') + strlen('group by'));
        $groupedBy = trim(str_replace(['"', '`', ' '], '', $tail));
        $this->assertSame('referrer_membership_id', $groupedBy);
    }

    public function test_top_referrers_ranking_counts_order_ties_and_scope(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        // Ranked field: counts 5, 4, 3, 2, then a three-way tie
        // at 1 for the last slot.
        $r1 = $this->membershipAt($business, 'Rita', now()->subDays(30));
        $this->redeem($r1, 5, now()->subDay());
        $r2 = $this->membershipAt($business, 'Ravi', now()->subDays(29));
        $this->redeem($r2, 4, now()->subDays(2));
        $r3 = $this->membershipAt($business, 'Rosa', now()->subDays(28));
        $this->redeem($r3, 3, now()->subDays(3));
        $r4 = $this->membershipAt($business, 'Rene', now()->subDays(27));
        $this->redeem($r4, 2, now()->subDays(4));

        // The tie at 1: the earliest-created membership takes
        // the fifth slot; the other two are cut by the limit.
        $tieOld = $this->membershipAt($business, 'Tess', now()->subDays(40));
        $this->redeem($tieOld, 1, now()->subDay());
        $tieMid = $this->membershipAt($business, 'Theo', now()->subDays(20));
        $this->redeem($tieMid, 1, now()->subDay());
        $tieNew = $this->membershipAt($business, 'Tara', now()->subDays(10));
        $this->redeem($tieNew, 1, now()->subDay());

        // Scope exclusions, each of which would out-rank the
        // field if it leaked in:
        $stale = $this->membershipAt($business, 'Stale', now()->subDays(26));
        $this->redeem($stale, 9, now()->subDays(8)); // redeemed, but outside the 7-day window
        $pending = $this->membershipAt($business, 'Pend', now()->subDays(25));
        for ($i = 0; $i < 8; $i++) {
            World::referral($pending, $this->customer('PendFriend'));
        }
        $removed = $this->membershipAt($business, 'Gone', now()->subDays(24));
        $this->redeem($removed, 7, now()->subDay());
        $removed->forceFill(['removed_at' => now()])->save();

        $otherBusiness = World::business();
        $outsider = $this->membershipAt($otherBusiness, 'Out', now()->subDays(23));
        $this->redeem($outsider, 6, now()->subDay());

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();

        $top = $response->viewData('topReferrers');
        $this->assertSame(
            [$r1->id, $r2->id, $r3->id, $r4->id, $tieOld->id],
            $top->map(fn (Membership $membership) => $membership->id)->all(),
        );
        $this->assertSame(
            [5, 4, 3, 2, 1],
            $top->map(fn (Membership $membership) => $membership->weekly_referrals)->all(),
        );
        // The hydration carries the customer the view renders.
        $this->assertSame('Rita', $top->first()->customer->first_name);
    }

    public function test_top_referrers_is_empty_when_nothing_was_redeemed_this_week(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, $this->customer('Quiet'));
        $this->redeem($membership, 3, now()->subDays(9));

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        $this->assertTrue($response->viewData('topReferrers')->isEmpty());
        $response->assertSee('No referrals yet.');
    }

    private function membershipAt(Business $business, string $firstName, $createdAt): Membership
    {
        [$membership] = World::membership($business, $this->customer($firstName));
        $membership->forceFill(['created_at' => $createdAt])->save();

        return $membership->fresh();
    }

    private function redeem(Membership $referrer, int $count, $redeemedAt): void
    {
        for ($i = 0; $i < $count; $i++) {
            World::referral($referrer, $this->customer('Friend'), [
                'state' => Referral::STATE_REDEEMED,
                'redeemed_at' => $redeemedAt,
            ]);
        }
    }

    private function customer(string $firstName)
    {
        $this->phoneSequence++;

        return World::customer('+1212555'.str_pad((string) $this->phoneSequence, 4, '0', STR_PAD_LEFT), $firstName, 'Test');
    }
}
