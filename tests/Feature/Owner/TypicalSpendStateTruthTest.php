<?php

namespace Tests\Feature\Owner;

use App\Mail\DunningRecoveredMail;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\Support\World;
use Tests\TestCase;

/**
 * P1 SPEND-DEFAULT (critic report 2026-10-29, SPEND-DEFAULT;
 * Amendment 57 family): surfaces asserted a typical spend
 * figure, and estimates computed from it, for owners who
 * never entered one. $50 was a default (the World fixture /
 * factory-world seed value) dressed as the owner's own
 * number, and $0.00 estimates printed on the same false
 * basis wherever the stored value was NULL or 0.
 *
 * The rule, in one place (Business::hasTypicalSpend): unset
 * is NULL or 0. Set, every surface renders the figure and
 * the estimates exactly as before. Unset, NO figure is
 * asserted and NO estimate claims the typical-spend basis:
 * the dashboard counts the real visits and fees and asks
 * for the figure; the statement omits its worth panel; the
 * dunning-recovered email omits the estimated-sales clause
 * and the Estimated value row; the Settings surfaces treat
 * a stored 0 as unset everywhere.
 */
class TypicalSpendStateTruthTest extends TestCase
{
    use RefreshDatabase;

    private function businessWithVisits(int $visits, array $overrides = []): Business
    {
        $business = World::business(array_merge(['free_visits_remaining' => 0], $overrides));
        [$membership] = World::membership($business, World::customer('+12125550988', 'Reg', 'Ular'));

        for ($i = 0; $i < $visits; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) (9300 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, [
                'state' => Referral::STATE_REDEEMED,
                'redeemed_at' => now(),
            ]);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }

        return $business->fresh();
    }

    private function renderMailText(Mailable $mail): string
    {
        $content = $mail->content();

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(view($content->view, $content->with)->render())));
    }

    // ---------------------------------------------------------------
    // Dashboard value panel
    // ---------------------------------------------------------------

    public function test_dashboard_unset_names_no_figure_and_no_estimate(): void
    {
        foreach ([null, 0] as $stored) {
            $business = $this->businessWithVisits(2, ['average_ticket_cents' => $stored]);
            $owner = World::owner($business);

            $page = $this->actingAs($owner)->get(route('dashboard'));
            $page->assertOk();
            $page->assertSee('2 visits counted this month');
            $page->assertSee('Visit fees so far this month: $4.00');
            $page->assertSee('Estimates need your typical spend per visit.');
            $page->assertDontSee('in estimated sales');
            $page->assertDontSee('typical spend per visit of');
            $page->assertDontSee('$0.00 in estimated sales');
        }
    }

    public function test_dashboard_set_renders_the_figure_and_the_estimates_as_before(): void
    {
        $business = $this->businessWithVisits(2, ['average_ticket_cents' => 5000]);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee('About $100.00 in estimated sales, based on your typical spend per visit (estimate)');
        $page->assertSee('Estimates use your typical spend per visit of $50.00');
        $page->assertDontSee('Estimates need your typical spend per visit.');
    }

    // ---------------------------------------------------------------
    // Statement worth panel
    // ---------------------------------------------------------------

    private function statementRecord(Business $business): BillingRecord
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_SUCCEEDED,
            'amount_cents' => 400,
            'currency' => 'USD',
            'idempotency_key' => 'spend-truth-stmt-'.$business->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);

        BillingEvent::query()
            ->where('business_id', $business->id)
            ->update(['billing_record_id' => $record->id]);

        return $record;
    }

    public function test_statement_omits_the_worth_panel_when_unset(): void
    {
        $business = $this->businessWithVisits(2, ['average_ticket_cents' => null]);
        $record = $this->statementRecord($business);

        $this->actingAs(World::owner($business))
            ->get(route('settings.billing.statement', $record))
            ->assertOk()
            ->assertDontSee('What those visits were worth');
    }

    public function test_statement_shows_the_worth_panel_when_set(): void
    {
        $business = $this->businessWithVisits(2, ['average_ticket_cents' => 5000]);
        $record = $this->statementRecord($business);

        $this->actingAs(World::owner($business))
            ->get(route('settings.billing.statement', $record))
            ->assertOk()
            ->assertSee('What those visits were worth (estimate): $100.00');
    }

    // ---------------------------------------------------------------
    // Dunning-recovered email
    // ---------------------------------------------------------------

    private function recoveredMail(Business $business): string
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_SUCCEEDED,
            'amount_cents' => 400,
            'currency' => 'USD',
            'idempotency_key' => 'spend-truth-mail-'.$business->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);
        BillingEvent::query()
            ->where('business_id', $business->id)
            ->update(['billing_record_id' => $record->id]);

        return $this->renderMailText(new DunningRecoveredMail($business, $record));
    }

    public function test_recovered_email_omits_the_estimate_when_unset(): void
    {
        $business = $this->businessWithVisits(2, ['average_ticket_cents' => null]);

        $text = $this->recoveredMail($business);
        $this->assertStringContainsString('This charge covered 2 visits that counted.', $text);
        $this->assertStringNotContainsString('estimated sales', $text);
        $this->assertStringNotContainsString('typical spend', $text);
        $this->assertStringNotContainsString('Estimated value', $text);
    }

    public function test_recovered_email_keeps_the_estimate_when_set(): void
    {
        $business = $this->businessWithVisits(2, ['average_ticket_cents' => 5000]);

        $text = $this->recoveredMail($business);
        $this->assertStringContainsString(
            'This charge covered 2 visits that counted, worth about $100 in estimated sales, based on your typical spend per visit (estimate).',
            $text
        );
        $this->assertStringContainsString('Estimated value', $text);
    }

    // ---------------------------------------------------------------
    // Settings surfaces: a stored 0 is unset, uniformly
    // ---------------------------------------------------------------

    public function test_settings_value_treats_a_stored_zero_as_unset(): void
    {
        $business = World::business(['average_ticket_cents' => 0]);
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings.value'))->assertOk()->getContent();
        // The live (Alpine) example branch renders, not the
        // server example that asserts a figure.
        $this->assertStringContainsString('x-show="ticket !== \'\'"', $html);
        $this->assertStringNotContainsString('At $0 a visit', $html);

        $index = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringNotContainsString('$0.00', $index);
    }

    public function test_the_unset_definition_is_one_place(): void
    {
        $this->assertFalse(World::business(['average_ticket_cents' => null])->hasTypicalSpend());
        $this->assertFalse(World::business(['average_ticket_cents' => 0])->hasTypicalSpend());
        $this->assertTrue(World::business(['average_ticket_cents' => 1])->hasTypicalSpend());
    }
}
