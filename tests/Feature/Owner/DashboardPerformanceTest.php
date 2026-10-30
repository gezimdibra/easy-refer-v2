<?php

namespace Tests\Feature\Owner;

use App\Models\BillingRecord;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\VolumeFixture;
use Tests\TestCase;

/**
 * Sprint 6 volume guard for the budget in verification protocol
 * Section 2.5 (test matrix XT-44): the owner dashboard's initial
 * load stays inside 2.5 s on throttled 4G with a business holding
 * 10,000 referrals of history, and no surface that shares its
 * aggregation may show N+1 behavior at that scale. The protocol
 * proves the N+1 half by query count; this test does the same
 * locally, asserting the counts at 1,000 and 10,000 referrals
 * are identical, and asserts the local server time for the
 * dashboard at 10k stays under 500 ms, a small fraction of the
 * 2.5 s client budget, so the local guard trips long before the
 * shipped budget could.
 *
 * The surfaces covered: the dashboard, the referrals list, and
 * the review queue. Fixture shape and its derivation from F-6
 * live in Tests\Support\VolumeFixture. Building both fixtures
 * adds roughly four seconds to the suite; the test is grouped
 * `perf` so it can be selected or excluded on its own.
 */
#[Group('perf')]
#[Group('slow')]
class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_shared_surfaces_hold_the_budget_at_volume(): void
    {
        $small = $this->measureSurfaces(1000);
        $large = $this->measureSurfaces(10000);

        // The fixture really is behind the page: the counters
        // read its rows (14 of every 20 referrals redeemed, 2 of
        // every 20 pending, per the VolumeFixture mix).
        $counters = $large['dashboard']['response']->viewData('counters');
        $this->assertSame(7000, $counters['completed_total']);
        $this->assertSame(1000, $counters['pending_total']);
        $this->assertSame(700, $small['dashboard']['response']->viewData('counters')['completed_total']);

        foreach (['dashboard', 'referrals', 'reviews'] as $surface) {
            // No N+1: the query count does not grow with volume.
            $this->assertSame(
                $small[$surface]['queries'],
                $large[$surface]['queries'],
                "Query count for {$surface} grew between 1k and 10k referrals (N+1)."
            );
            // Server time at 10k stays a small fraction of the
            // 2.5 s throttled-4G budget in protocol Section 2.5.
            $this->assertLessThan(
                500,
                $large[$surface]['ms'],
                "Server time for {$surface} at 10k referrals exceeded 500 ms."
            );
        }

        // The dashboard's initial-load query count stays a small
        // constant, never a function of history. Calibrated at
        // 26 measured in Sprint 6 (per-panel counts plus two
        // one-time relation loads), cap 30. The base had since
        // drifted +2: the immediate pre-Train-2 tree (5c366db)
        // measures 28 at both volumes (the Train 2 review's
        // probe, T2R-02). Recalibrated 2026-10-25 for Train 2,
        // whose shell and panels are deliberate dashboard
        // additions with measured constant costs: the RK-01
        // Action Center badge read model (7 queries: dismissals,
        // one shared card lookup, oldest held reward, both hold
        // sets, latest failed-delivery campaign, first-redemption
        // check), the RK-03 forecast preview (1), and LV-02's
        // added checklist step in the panel's own computation
        // (1; the badge reuses the panel's checklist rather than
        // recomputing it). 28 + 9 = 37 measured at BOTH volumes,
        // cap 40. Recalibrated 2026-10-27 for the B7 query
        // deduplication (M-1..M-7 in the PM profiling lane's
        // report): the held collections, the chart pluck, the
        // settlement walk, the dismissal map, the checklist's
        // card lookup, and the first-redemption check are each
        // fetched once per request and handed to every consumer
        // instead of being re-queried per panel. 28 measured at
        // BOTH volumes — the equality assertions above remain
        // the N+1 proof; cap 31 keeps the same measured + 3
        // headroom convention as the original calibration.
        $this->assertLessThanOrEqual(31, $large['dashboard']['queries']);
    }

    /**
     * The query count is state-dependent (B7 profile, 2026-10-27):
     * the volume fixture's business is billing-active with no
     * card and no failed campaign, so it never exercises the
     * Action Center's past-due read (the newest failed billing
     * record) or its failed-deliveries recipient count. Pre-fix
     * those states measured 40 (past-due + card + failed
     * campaign) and 41 (the same with no card) against the old
     * cap of 40 — the second already over it with no new feature
     * involved. Both states must hold the same budget as the
     * fixture state post-deduplication.
     */
    public function test_dashboard_holds_the_budget_in_past_due_states(): void
    {
        [$business, $owner] = VolumeFixture::businessWithHistory(1000);

        $business->forceFill(['billing_status' => 'past_due'])->save();
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 400,
            'currency' => 'USD',
            'idempotency_key' => 'batch_perf_past_due',
            'state' => BillingRecord::STATE_FAILED,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);

        $membership = Membership::query()->where('business_id', $business->id)->firstOrFail();
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'A past campaign',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'sent_at' => now(),
        ]);
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id,
            'phone_e164' => '+12125559999',
            'delivery_state' => SmsCampaignRecipient::STATE_FAILED,
        ]);

        $card = PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_perf',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(3)->format('Y'),
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);

        // Recalibrated 2026-10-28 for Train A2 (truth train):
        // the forecast's text leg needs the accrued text total
        // on this page, one conditional-sum query covering both
        // the forecast and the Value panel's month figure; the
        // gate's card fact reuses the checklist's existing card
        // lookup, so the train's net measured cost is exactly
        // +1 in every state (plain volume 28 -> 29, these
        // past-due states 31 -> 32). The N+1 equality proof
        // above is unchanged; cap here is measured + 0 because
        // these states were already at the old cap.
        //
        // Recalibrated 2026-10-29 for ID-06 (Amendment 49):
        // the second-placement Action Center source reads its
        // combined state/settled/armed probe in exactly one
        // query in every dashboard state (its prints and scan
        // derivations run only inside the prompt's 14-day
        // window or on the Action Center page read), so the
        // train's net measured cost is exactly +1 in every
        // state (plain volume 29 -> 30, under the standing cap
        // of 31; these past-due states 32 -> 33 with a card,
        // 31 -> 32 without). The N+1 equality proof above is
        // unchanged; the with-card cap below is measured + 0,
        // the same convention as the A2 recalibration, because
        // that state was already at its old cap.
        // Recalibrated 2026-10-30 for the FLOW-STATE TRUTH
        // train: a past-due episode dates from the business's
        // billing state itself (Business::dunningGraceEndsAt
        // falls back to the failed record when the audit row
        // is absent — the state this fixture is in), which is
        // exactly one extra indexed read on the past-due
        // dashboard; the train's net measured cost is +1 in
        // the past-due states only (with-card 33 -> 34,
        // no-card 32 -> 33). The N+1 equality proof above is
        // unchanged.
        // Tightened back 2026-10-30 by the two-key Finding 1
        // fix (Finding 5 consequence): the failed-record
        // fallback read is REMOVED — dunningGraceEndsAt reads
        // only the audit row and fails closed without one — so
        // the +1 is gone and the budgets return to the pre-train
        // values (with-card 34 -> 33, no-card 33 -> 32),
        // re-measured on the fixed tree.
        $withCard = $this->measure($owner, route('dashboard'));
        $this->assertLessThanOrEqual(
            33,
            $withCard['queries'],
            'Dashboard query count for a past-due business with a card and a failed campaign exceeded the budget.'
        );

        $card->delete();

        $noCard = $this->measure($owner->fresh(), route('dashboard'));
        // Recalibrated 2026-10-30 for the ACTIVATION REDESIGN:
        // with the card deleted, the dashboard's allowance
        // reads (the gate's own BillingEvent participant count,
        // Business::allowanceUsed()) run on this path — one
        // indexed aggregate query, memoized per request. Net
        // measured cost is exactly +1 in the no-card state only
        // (33 -> 34); the with-card state above pays nothing
        // (hasCardOnFile short-circuits the count). Same
        // convention as the two recalibrations above.
        $this->assertLessThanOrEqual(
            34,
            $noCard['queries'],
            'Dashboard query count for a past-due business with a failed campaign and no card exceeded the budget.'
        );
    }

    /**
     * @return array{dashboard: array{queries: int, ms: float, response: \Illuminate\Testing\TestResponse}, referrals: array{queries: int, ms: float}, reviews: array{queries: int, ms: float}}
     */
    private function measureSurfaces(int $volume): array
    {
        [, $owner] = VolumeFixture::businessWithHistory($volume);

        return [
            'dashboard' => $this->measure($owner, route('dashboard'), timedHits: 2),
            'referrals' => $this->measure($owner, route('referrals.index')),
            'reviews' => $this->measure($owner, route('reviews')),
        ];
    }

    /**
     * Query count from the first hit (the true initial load);
     * time is the better of $timedHits hits so a one-off pause
     * on a shared runner cannot flake the budget while a real
     * regression, which is never marginal here, still trips it.
     *
     * @return array{queries: int, ms: float, response: \Illuminate\Testing\TestResponse}
     */
    private function measure(User $owner, string $url, int $timedHits = 1): array
    {
        $queries = null;
        $best = null;
        $response = null;

        for ($hit = 0; $hit < $timedHits; $hit++) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $start = hrtime(true);
            $response = $this->actingAs($owner)->get($url);
            $ms = (hrtime(true) - $start) / 1e6;

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            $response->assertOk();
            $queries ??= $count;
            $best = $best === null ? $ms : min($best, $ms);
        }

        return ['queries' => $queries, 'ms' => $best, 'response' => $response];
    }
}
