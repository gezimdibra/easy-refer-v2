<?php

namespace Tests\Feature\Owner;

use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use App\Services\Referral\RedemptionService;
use App\Support\ReferralFeeLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RS2-1 (Value Train, Amendment 44): the per-visit fee line on
 * the Referrals list + detail. The line reads the settlement
 * walk's outcome (VisitValuation::settle), NEVER the stored
 * billing_events.coverage mint default. Test IDs T1-1..T1-14
 * refer to the value-train design pass, Part 1, Section 1.6.
 */
class ReferralFeeLineTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 9100;

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    /** A redeemed referral with its minted billing event. */
    private function redeemed(Business $business, Membership $membership, ?string $coverage = 'billable', int $amountCents = 200): array
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now(),
        ]);
        // Stagger creation by sequence so the index's
        // created_at-desc order is deterministic in the
        // cap-boundary test (all rows share one second
        // otherwise, and pagination order would be a tie).
        // Post-create forceFill, the FraudHoldConversionTest
        // pattern: create-time timestamps do not survive.
        $referral->forceFill(['created_at' => now()->subMinutes(10000 - $this->friendSequence)])->save();
        $referral = $referral->fresh();

        $event = $coverage === null ? null : BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
            'coverage' => $coverage,
        ]);

        return [$referral, $event];
    }

    private function detail(Business $business, Referral $referral): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(World::owner($business))->get(route('referrals.show', $referral));
    }

    private function index(Business $business): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(World::owner($business))->get(route('referrals.index'));
    }

    // T1-1: billable, unclaimed -> B1.
    public function test_billable_unclaimed_detail_and_list_lines(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$referral] = $this->redeemed($business, $membership, 'billable');

        $this->detail($business, $referral)
            ->assertOk()
            ->assertSee('Fee: $2.00, in your next monthly charge.');

        $this->index($business)->assertSee('Fee: $2.00');
    }

    // T1-2: the stored mint default says billable; the walk says
    // free_credit. The line must follow the WALK (the core
    // RS2-1 rule), and {n} renders from config, never a literal.
    public function test_free_credit_line_follows_the_walk_not_the_stored_coverage(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);
        [$referral] = $this->redeemed($business, $membership, 'billable');

        $this->detail($business, $referral)
            ->assertSee('Fee: $0.00, one of your 5 free visits.')
            ->assertDontSee('Fee: $2.00');

        $this->index($business)->assertSee('Fee: $0.00, free visit');

        config(['billing.free_visits_at_signup' => 7]);
        $this->detail($business, $referral)
            ->assertSee('Fee: $0.00, one of your 7 free visits.');
    }

    // T1-3: pending -> detail P1 line; the list row gains NO fee
    // text at all (absence pin, both layouts render in one page).
    public function test_pending_detail_line_and_list_absence(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        $friend = World::customer('+12125550101', 'Penny', 'Pending');
        [$referral] = World::referral($membership, $friend);

        $this->detail($business, $referral)
            ->assertSee('Fee: No fee yet. A fee is set only when your friend visits and the visit counts.');

        $this->index($business)->assertDontSee('Fee:');
    }

    // T1-4: expired + voided -> P3/P4 lines; no billing event.
    public function test_expired_and_voided_detail_lines(): void
    {
        $business = World::business();
        $membership = $this->membership($business);

        [$expired] = World::referral($membership, World::customer('+12125550102', 'Erin', 'Expired'), [
            'state' => Referral::STATE_EXPIRED,
        ]);
        [$voided] = World::referral($membership, World::customer('+12125550103', 'Vic', 'Voided'), [
            'state' => Referral::STATE_VOIDED,
        ]);

        $this->assertSame(0, BillingEvent::count());

        $this->detail($business, $expired)
            ->assertSee('Fee: $0.00. No visit happened, so there is no fee.');
        $this->detail($business, $voided)
            ->assertSee('Fee: $0.00. This referral was denied before a visit, so there is no fee.');

        $this->index($business)->assertDontSee('Fee:');
    }

    // T1-5 + T1-6: the cap boundary. The 49th billed visit reads
    // B1; the 50th reads C1 (past the cap) and NEVER the
    // free-visit wording, though both are $0.00 apart in kind.
    public function test_cap_boundary_49th_bills_50th_is_past_the_cap_not_free(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        for ($i = 0; $i < 48; $i++) {
            $this->redeemed($business, $membership, 'billable');
        }

        [$fortyNinth] = $this->redeemed($business, $membership, 'billable');
        $this->detail($business, $fortyNinth)
            ->assertSee('Fee: $2.00, in your next monthly charge.');

        [$fiftieth] = $this->redeemed($business, $membership, 'billable');
        $this->detail($business, $fiftieth)
            ->assertSee('Fee: $0.00, past your $99 monthly cap.')
            ->assertDontSee('free visit');

        $this->index($business)
            ->assertSee('Fee: $0.00, past the cap')
            ->assertDontSee('Fee: $0.00, free visit');
    }

    /**
     * A genuinely held visit through the shipped r46 mechanics:
     * the second redemption in a day converts (velocity) to a
     * held referrer reward + coverage='held' billing event while
     * the friend's visit completes.
     *
     * @return array{0: Referral, 1: \App\Models\Reward}
     */
    private function heldVisit(Business $business): array
    {
        $membership = $this->membership($business);
        $service = app(RedemptionService::class);

        $mk = function () use ($membership) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            $referral->forceFill(['created_at' => now()->subHours(3)])->save();

            return $referral->fresh();
        };

        $first = $mk();
        $this->assertSame('redeemed', $service->redeemReferral($first, null, '127.0.0.1')['outcome']);

        $second = $mk();
        $result = $service->redeemReferral($second, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertTrue($result['reward']->hold, 'precondition: second visit held by the velocity conversion');
        $this->assertSame('held', BillingEvent::query()->where('referral_id', $second->id)->firstOrFail()->coverage);

        return [$second->fresh(), $result['reward']];
    }

    // T1-9: held (r46 mechanics) -> H1 lines, NO dollar figure.
    public function test_held_visit_line_promises_no_dollar_figure(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 0]);
        [$held] = $this->heldVisit($business);

        $this->detail($business, $held)
            ->assertSee('No fee yet. This visit is waiting on your review. If you release it, its fee is settled then; if you deny it, there is never a fee.')
            ->assertDontSee('Fee: $');

        $this->index($business)->assertSee('Fee: held for review');
    }

    // T1-7 (free outcome): release while free allowance remains
    // settles the released visit as a FREE visit, not $2.00 —
    // the H1 wording ("its fee is settled then") survives this.
    public function test_held_release_can_settle_as_a_free_visit(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 5]);
        [$held, $reward] = $this->heldVisit($business);

        app(RedemptionService::class)->releaseReward($reward);

        $this->detail($business, $held)
            ->assertSee('Fee: $0.00, one of your 5 free visits.')
            ->assertDontSee('Fee: $2.00');
    }

    // T1-7 (billable outcome + bills exactly once): with the
    // allowance spent, the released visit settles billable; the
    // event already exists (release mints nothing new) and the
    // accrued preview counts its fee exactly once.
    public function test_held_release_bills_exactly_once_when_allowance_is_spent(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 0]);
        [$held, $reward] = $this->heldVisit($business);

        app(RedemptionService::class)->releaseReward($reward);

        $this->assertSame(1, BillingEvent::query()->where('referral_id', $held->id)->count());

        $preview = app(SettlementService::class)->accruedPreview($business->fresh());
        // First (clean) visit 200 + released held visit 200, once each.
        $this->assertSame(400, $preview['accrued_cents']);
        $this->assertSame(2, $preview['billable_events']);

        $this->detail($business, $held)
            ->assertSee('Fee: $2.00, in your next monthly charge.');
    }

    // T1-8: held -> deny -> H3 line; a held visit consumed no
    // free slot, so none is restored. Plus the stored-free_credit
    // deny path, where the slot IS restored (r26 rule read
    // precisely) and the same H3 line renders.
    public function test_held_deny_line_and_free_slot_rules(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 0]);
        [$held, $reward] = $this->heldVisit($business);

        app(RedemptionService::class)->denyReward($reward);

        $this->assertSame('fraud_denied', BillingEvent::query()->where('referral_id', $held->id)->firstOrFail()->coverage);
        $this->assertSame(0, (int) $business->fresh()->free_visits_remaining, 'a held visit never consumed a free slot, so deny restores none');

        $this->detail($business, $held)
            ->assertSee('Fee: $0.00. You denied this visit in review, so there is no fee.');
        $this->index($business)->assertSee('Fee: $0.00');

        $preview = app(SettlementService::class)->accruedPreview($business->fresh());
        $this->assertSame(200, $preview['accrued_cents'], 'only the clean first visit accrues; the denied visit never appears');

        // Stored free_credit deny: the slot comes back.
        $business2 = World::business(['free_visits_remaining' => 4]);
        $membership2 = $this->membership($business2);
        [$referral2, $event2] = $this->redeemed($business2, $membership2, 'free_credit');
        // BREAKER D-2: deny resolves a held review, so the
        // stored-free_credit reward is fixtured as held.
        [$reward2] = World::reward($membership2, $referral2, ['state' => \App\Models\Reward::STATE_AVAILABLE, 'hold' => true, 'held_at' => now(), 'hold_rules' => ['review']]);

        app(RedemptionService::class)->denyReward($reward2);

        $this->assertSame('fraud_denied', $event2->fresh()->coverage);
        $this->assertSame(5, (int) $business2->fresh()->free_visits_remaining);
        $this->detail($business2, $referral2)
            ->assertSee('Fee: $0.00. You denied this visit in review, so there is no fee.');
    }

    // T1-10 + ruling B3: full refund -> R1; a PARTIAL refund
    // leaves coverage billable and the fee line states the fee
    // (B2), because the refund remainder lives on the statement.
    public function test_refunded_and_partially_refunded_lines(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'fee_line_refunded',
            'state' => BillingRecord::STATE_REFUNDED,
        ]);
        [$refundedRef, $refundedEvent] = $this->redeemed($business, $membership, 'refunded');
        $refundedEvent->forceFill(['billing_record_id' => $record->id])->save();

        $this->detail($business, $refundedRef)->assertSee('Fee: $0.00, refunded.');
        $this->index($business)->assertSee('Fee: $0.00, refunded');

        $partial = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'fee_line_partial',
            'state' => BillingRecord::STATE_PARTIALLY_REFUNDED,
        ]);
        [$partialRef, $partialEvent] = $this->redeemed($business, $membership, 'billable');
        $partialEvent->forceFill(['billing_record_id' => $partial->id])->save();

        $this->detail($business, $partialRef)
            ->assertSee('Fee: $2.00, on your monthly charge.');
    }

    // T1-12: an event claimed by a settled-state record renders
    // from its PERSISTED coverage, mirroring settleAndPersist's
    // guard — here the walk alone would call it free_credit
    // (allowance remains), but the charge already billed it.
    public function test_claimed_by_settled_record_prefers_persisted_coverage(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);

        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'fee_line_settled_claim',
            'state' => BillingRecord::STATE_SUCCEEDED,
        ]);
        [$referral, $event] = $this->redeemed($business, $membership, 'billable');
        $event->forceFill(['billing_record_id' => $record->id])->save();

        // The walk's own answer for contrast: free_credit.
        $walk = (new VisitValuation)->settle($business->fresh());
        $this->assertSame('free_credit', $walk['settled'][$event->id]['coverage']);

        $this->detail($business, $referral)
            ->assertSee('Fee: $2.00, on your monthly charge.')
            ->assertDontSee('free visits');
    }

    // B2 plain: claimed by a succeeded record, allowance spent.
    public function test_billable_claimed_line(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'fee_line_claimed',
            'state' => BillingRecord::STATE_SUCCEEDED,
        ]);
        [$referral, $event] = $this->redeemed($business, $membership, 'billable');
        $event->forceFill(['billing_record_id' => $record->id])->save();

        $this->detail($business, $referral)
            ->assertSee('Fee: $2.00, on your monthly charge.');
        $this->index($business)->assertSee('Fee: $2.00');
    }

    // T1-11: billing STATUS (closed / past-due) does not alter a
    // settled line (X1).
    public function test_closed_and_past_due_businesses_keep_their_settled_lines(): void
    {
        $closed = World::business(['free_visits_remaining' => 0, 'status' => 'closed']);
        $membership = $this->membership($closed);
        [$referral] = $this->redeemed($closed, $membership, 'billable');

        $line = ReferralFeeLine::present($referral, $referral->billingEvent, (new VisitValuation)->settle($closed)['settled'][$referral->billingEvent->id] ?? null, null, $closed);
        $this->assertSame('Fee: $2.00, in your next monthly charge.', $line['detail']);
    }

    // T1-13: reconciliation — the per-visit lines for a mixed
    // month sum to the walk's month fees, kind by kind.
    public function test_mixed_month_lines_reconcile_with_the_walk(): void
    {
        $business = World::business(['free_visits_remaining' => 2]);
        $membership = $this->membership($business);

        [$free1] = $this->redeemed($business, $membership, 'billable');
        [$free2] = $this->redeemed($business, $membership, 'billable');
        [$billed] = $this->redeemed($business, $membership, 'billable');
        [$heldRef] = $this->redeemed($business, $membership, 'held');
        [$denied] = $this->redeemed($business, $membership, 'fraud_denied');

        $valuation = new VisitValuation;
        $month = $valuation->forMonth($business->fresh(), now());
        $this->assertSame(200, $month['fees_cents']);
        $this->assertSame(5, $month['verified_visits']);

        // {n} is the signup allowance from config (REFL-13f),
        // not this fixture's remaining balance.
        $this->detail($business, $free1)->assertSee('Fee: $0.00, one of your 5 free visits.');
        $this->detail($business, $free2)->assertSee('Fee: $0.00, one of your 5 free visits.');
        $this->detail($business, $billed)->assertSee('Fee: $2.00, in your next monthly charge.');
        $this->detail($business, $heldRef)->assertSee('No fee yet. This visit is waiting on your review.');
        $this->detail($business, $denied)->assertSee('Fee: $0.00. You denied this visit in review, so there is no fee.');
    }

    // T1-14: query discipline — one settlement walk per index
    // request, never one per row. 25 redeemed rows; the number
    // of billing_events queries stays a small constant.
    public function test_index_runs_one_settlement_walk_for_a_full_page(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        for ($i = 0; $i < 25; $i++) {
            $this->redeemed($business, $membership, 'billable');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->index($business)->assertOk()->assertSee('Fee: $2.00');
        $queries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'billing_events'))
            ->count();
        DB::disableQueryLog();

        // RE-DERIVED (activation redesign, 2026-10-30): the
        // cap moves 3 -> 4. The +1 is the activation gate's
        // own allowance count (Business::allowanceUsed(), one
        // aggregate over billing_events) which the index's
        // page-level reads now run beside the settlement walk.
        // The pin's intent is unchanged and still proven by
        // the constant: 25 rows, a small constant number of
        // billing_events queries, never one per row.
        $this->assertLessThanOrEqual(4, $queries, 'index must not walk per row (billing_events queries: '.$queries.')');
    }
}
