<?php

namespace Tests\Feature\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Services\Billing\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The billing page (billing UI spec Sections 1 and 2, build
 * unit BU-2): one page in Settings whose every figure binds to
 * the same sources the dashboard and the batch use, so the
 * numbers agree by construction. Rendering the page persists
 * nothing and claims nothing: the accrued figure comes from the
 * settlement walk as a preview, never from a charge run.
 */
class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 7000;

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550989', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function event(Business $business, Membership $membership, string $coverage = 'billable', int $amountCents = 200): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        return BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
            'coverage' => $coverage,
        ]);
    }

    private function card(Business $business, array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'business_id' => $business->id,
            'square_card_id' => 'card_test_1',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_test1',
        ], $overrides));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('settings.billing'))->assertRedirect(route('login'));
    }

    public function test_setup_state_renders_plan_no_card_and_honesty_line(): void
    {
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        // Plan block (DASH-15 from the plan row).
        $response->assertSee('Current plan');
        $response->assertSee('Pay Per Visit');
        $response->assertSee('Never more than $99 a month in visit charges');
        // Merge ruling (lanes 3 x FE-CANCEL, 2026-10-26): the R2
        // draft moved this sentence off the page, but it does not
        // in fact live on Account (only the Close account button
        // does); the interim disclosure stays on Billing.
        $response->assertSee('To stop billing, end your program in Settings, under Account. Your final bill covers the visits and texts you already have.');
        // No-card block (BILL-01) and the charges-off honesty line (BILL-10).
        // RE-DERIVED (activation redesign, census C-16,
        // ACT-BILL-01): a cardless business with its allowance
        // untouched is ON — the panel's heading is the code's
        // state and the body carries the gate's own count.
        $response->assertSee('Card on file');
        $response->assertSee('Your code is on');
        $response->assertSee('You have 5 of your 5 free visits left. After those, your code pauses until a card is on file. Nothing is charged when you add your card.');
        $response->assertDontSee('Add a card to turn on your QR code');
        $response->assertSee('Charges are not switched on yet. Visits still count, and this page keeps the running total.');
        // This month at zero, and the empty history (BILL-15).
        $response->assertSee('This month');
        // R2 BILL: this month is tiles now (label + value).
        $response->assertSee('Visits counted');
        $response->assertSee('Free visits left');
        $response->assertSee('5 of 5');
        $response->assertSee('Visit fees so far');
        $response->assertDontSee('Built up so far:');
        $response->assertSee('Charges');
        $response->assertSee('No charges yet. Your first charge comes after your 5 free visits.');
    }

    public function test_no_card_panel_reflects_exhausted_free_visits(): void
    {
        // T3-F5: an established business whose free visits are
        // gone must not be told its first 5 visits are free or
        // that adding a card turns its QR code on (it never went
        // off).
        //
        // RE-DERIVED (activation redesign, census C-17): the
        // panel reads the settlement walk's own count over
        // BillingEvent participants, never the lagged
        // free_visits_remaining column, so this fixture seeds
        // the ledger itself — five counted visits — instead of
        // stating the column. The form is ACT-BILL-02, the one
        // state where a turn-back-on heading is true again.
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        for ($i = 0; $i < 5; $i++) {
            $this->event($business, $membership);
        }
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertDontSee('Add a card to turn on your QR code');
        $response->assertDontSee('Your first 5 visits are free');
        $response->assertSee('Your code is paused');
        $response->assertSee('Your 5 free visits are used up. Add a card to turn your code back on. Nothing is charged when you add your card.');
    }

    public function test_no_card_panel_counts_the_free_visits_still_left(): void
    {
        // RE-DERIVED (activation redesign, census C-17/C-21):
        // the count is the ledger's (3 participants seeded, 2
        // left), and the panel and the tile print that same
        // number — the column's stale 2 is now a coincidence
        // the fixture keeps only as settlement state.
        $business = World::business(['free_visits_remaining' => 2]);
        $membership = $this->membership($business);
        for ($i = 0; $i < 3; $i++) {
            $this->event($business, $membership);
        }
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertDontSee('Add a card to turn on your QR code');
        $response->assertDontSee('Your first 5 visits are free');
        $response->assertSee('You have 2 of your 5 free visits left. After those, your code pauses until a card is on file. Nothing is charged when you add your card.');
        $response->assertSee('2 of 5');
    }

    public function test_plan_block_states_the_configured_per_visit_price(): void
    {
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        // The per-visit figure is the configured per-referral price
        // (OD-1). The seeded pay-per-visit row carries price_cents
        // null by design (that column is the subscription slot), and
        // formatting the slot produced "$0.00 per visit" (FLOWS-F1):
        // the block must state the plan's per-visit price instead.
        $expected = '$'.number_format(((int) config('billing.price_per_verified_visit_cents')) / 100, 2);
        $response->assertSee($expected.' per visit');
        $response->assertDontSee('$0.00 per visit');
    }

    public function test_active_business_with_card_and_month_activity(): void
    {
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 0]);
        $owner = World::owner($business);
        $membership = $this->membership($business);
        $this->card($business);

        // The batch-persisted shape: five free, then two billable.
        for ($i = 0; $i < 5; $i++) {
            $this->event($business, $membership, 'free_credit');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->event($business, $membership, 'billable');
        }

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        // Card line (DASH-14 verbatim) and expiry.
        // R2 BILL: the card is a wallet row with a state pill.
        $response->assertSee('•••• 5858');
        $response->assertSee('On file');
        $response->assertSee('Expires 03/2028');
        $response->assertDontSee('Add a card to turn on your QR code');
        // This month agrees with the dashboard's own computation.
        $response->assertSee('Visits counted');
        $response->assertSee('Free visits left');
        $response->assertSee('0 of 5');
        $response->assertSee('Visit fees so far');
        $response->assertSee('$4.00 of $99 monthly cap');
        // R2 BILL: the forecast headline states the accrued figure once.
        $response->assertSee('Expected charge on');
        $response->assertDontSee('under $1, so it carries forward');
    }

    public function test_accrued_preview_counts_only_unclaimed_billable_events_and_persists_nothing(): void
    {
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        // One small unclaimed billable event (under the $1 minimum),
        // one already claimed by a record: the claim is outside the
        // preview by definition.
        $small = $this->event($business, $membership, 'billable', 50);
        $claimed = $this->event($business, $membership, 'billable');
        $claimed->forceFill(['billing_record_id' => 999])->save();

        $preview = app(SettlementService::class)->accruedPreview($business->fresh());

        $this->assertSame(50, $preview['accrued_cents']);
        $this->assertSame(1, $preview['billable_events']);

        // The preview wrote nothing: no coverage rewrite, no record,
        // no credit movement.
        $this->assertSame('billable', $small->fresh()->coverage);
        $this->assertSame(0, DB::table('billing_records')->count());
        $this->assertSame(0, $business->fresh()->free_visits_remaining);
    }

    public function test_under_minimum_accrual_reads_the_carry_forward_line(): void
    {
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 0]);
        $owner = World::owner($business);
        $membership = $this->membership($business);
        $this->event($business, $membership, 'billable', 50);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        // Train A2 (A8): a sub-$1 total charges nothing on the
        // scheduled date, so no forecast line may name one;
        // the carry-forward line owns this state.
        $response->assertDontSee('Expected charge on');
        $response->assertSee('Your amount so far is under $1, so it carries forward. It joins your next monthly charge.');
    }

    public function test_past_due_banner_renders_the_dash_copy(): void
    {
        // FLOW-STATE TRUTH train: this fixture's past_due has
        // no audit row and no failed record — an undatable
        // episode, which fails closed. The banner states the
        // paused truth; the dateless grace line never renders.
        $business = World::business(['billing_status' => 'past_due']);
        $owner = World::owner($business);
        $this->card($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertSee('A payment failed. Please update your card to keep billing current.');
        $response->assertSee('Update card');
        $response->assertSee('Your QR code and customer links are paused until you update your card.');
        $response->assertDontSee('keep working during the grace period');
    }

    public function test_active_without_a_card_shows_the_pause_banner(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        // RE-DERIVED (activation redesign, census C-18,
        // ACT-BILL-03a): the keeps-counting banner names the
        // free visits it is true for.
        $response->assertSee('No card on file. Visits keep counting while your free visits last (5 left), and the amount builds up here.');
    }

    public function test_pending_card_renders_the_confirming_state(): void
    {
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);
        $this->card($business, [
            'state' => PaymentMethod::STATE_PENDING,
            'is_default' => false,
            'idempotency_key' => 'card_'.$business->id.'_pending1',
        ]);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertSee('We are still confirming your card with Square. This page will update on its own. Please do not add the card again.');
        $response->assertDontSee('Card on file ending');
    }

    public function test_expired_default_card_carries_the_expired_note(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $this->card($business, ['exp_month' => 1, 'exp_year' => 2020]);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        // R2 BILL: the wallet row's pill carries the expired state.
        $response->assertSee('•••• 5858');
        $response->assertSee('Expired');
        $response->assertSee('This card has expired. Replace it to keep monthly charges going.');
        // SV-02: an expired card shows the expired state only,
        // never the approaching-expiry pill.
        $response->assertDontSee('Expires soon');
        $response->assertDontSee('This card expires soon. Replace it to keep monthly charges going.');
    }

    public function test_card_expiring_within_sixty_days_carries_the_expires_soon_pill(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $soon = now()->addDays(20);
        $this->card($business, ['exp_month' => $soon->month, 'exp_year' => $soon->year]);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertSee('pill-warning', false);
        $response->assertSee('Expires soon');
        $response->assertSee('This card expires soon. Replace it to keep monthly charges going.');
        $response->assertDontSee('This card has expired. Replace it to keep monthly charges going.');
        // The warning sits on the card panel, beside the
        // existing Replace action.
        $response->assertSee('Replace card');
    }

    public function test_card_expiring_beyond_sixty_days_carries_no_warning(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $owner = World::owner($business);
        $later = now()->addDays(120);
        $this->card($business, ['exp_month' => $later->month, 'exp_year' => $later->year]);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertDontSee('Expires soon');
        $response->assertDontSee('This card expires soon. Replace it to keep monthly charges going.');
    }

    public function test_rendering_the_page_persists_nothing(): void
    {
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 3]);
        $owner = World::owner($business);
        $membership = $this->membership($business);
        $this->event($business, $membership);
        $this->event($business, $membership);

        $before = [
            'events' => DB::table('billing_events')->count(),
            'records' => DB::table('billing_records')->count(),
            'coverages' => DB::table('billing_events')->pluck('coverage')->all(),
            'free' => $business->fresh()->free_visits_remaining,
            'audits' => DB::table('audit_logs')->count(),
        ];

        $this->actingAs($owner)->get(route('settings.billing'))->assertOk();

        $after = [
            'events' => DB::table('billing_events')->count(),
            'records' => DB::table('billing_records')->count(),
            'coverages' => DB::table('billing_events')->pluck('coverage')->all(),
            'free' => $business->fresh()->free_visits_remaining,
            'audits' => DB::table('audit_logs')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_settings_index_and_subnav_reach_billing(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings'))
            ->assertOk()
            ->assertSee(route('settings.billing'))
            ->assertSee('Billing');

        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }
}
