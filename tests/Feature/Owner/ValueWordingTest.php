<?php

namespace Tests\Feature\Owner;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Referral;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Amendment 57 (owner ruling 2026-10-29, Finding 5): the value
 * question asks about a typical visit, never a first visit.
 * The owner-facing framing for businesses.average_ticket_cents
 * is now "How much does a typical customer spend in one
 * visit?" / "Typical spend per visit ($)" / "About what a
 * customer spends on one visit at your store. Your Overview
 * uses it to estimate the sales your visits brought in."
 * The rename is wording only: the stored value and the
 * estimate formula (visits x the stored figure) do not move.
 */
class ValueWordingTest extends TestCase
{
    use RefreshDatabase;

    private const HEADING = 'How much does a typical customer spend in one visit?';

    private const BODY = 'About what a customer spends on one visit at your store. Your Overview uses it to estimate the sales your visits brought in.';

    private const LABEL = 'Typical spend per visit ($)';

    private function businessWithVisits(int $visits, array $overrides = []): Business
    {
        $business = World::business(array_merge(['average_ticket_cents' => 5000, 'free_visits_remaining' => 0], $overrides));
        [$membership] = World::membership($business, World::customer('+12125550988', 'Reg', 'Ular'));

        for ($i = 0; $i < $visits; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) (9400 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End');
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

        return $business;
    }

    // The dashboard value card: the estimate lines and the
    // helper name the typical-spend basis, keep the word
    // "estimate", and carry none of the old framing.
    public function test_dashboard_value_card_uses_typical_spend_wording(): void
    {
        $business = $this->businessWithVisits(2);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee('About $100.00 in estimated sales, based on your typical spend per visit (estimate)');
        $page->assertSee('Estimates use your typical spend per visit of $50.00');
        $page->assertDontSee('first-visit sales');
        $page->assertDontSee('average ticket');
        $page->assertDontSee('First visit spend');
        $page->assertDontSee('What is a first visit worth?');
    }

    // Settings -> Value: the same heading, label, and body as
    // the onboarding panel, the reassurance line unchanged,
    // the worked example in the new wording, and none of the
    // old framing. The Settings index row names the new basis.
    public function test_settings_value_renders_the_new_question(): void
    {
        $business = World::business(['average_ticket_cents' => 3500]);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('settings.value'));
        $page->assertOk();
        $page->assertSee(self::HEADING);
        $page->assertSee(self::BODY);
        $page->assertSee(self::LABEL, false);
        $page->assertSee('Your best guess is fine. You can change it any time, and changing it never changes what you are charged.');
        $page->assertSee('At $35 a visit, 10 visits estimate about $350 in estimated sales on your Overview.');
        $page->assertDontSee('Average visit value');
        $page->assertDontSee('Your rough average sale');
        $page->assertDontSee('first-visit sales');

        $this->actingAs($owner)->get(route('settings'))
            ->assertOk()
            ->assertSee('Your typical spend per visit, used for the sales estimates on your Overview.')
            ->assertDontSee('Your average ticket, used for the sales estimates');
    }

    // Settings -> Value, unset branch: the live Alpine example
    // carries the same new wording (no first-visit sales).
    public function test_settings_value_unset_example_uses_the_new_wording(): void
    {
        $business = World::business(['average_ticket_cents' => null]);

        $html = $this->actingAs(World::owner($business))->get(route('settings.value'))->assertOk()->getContent();
        $this->assertStringContainsString('in estimated sales on your Overview.', $html);
        $this->assertStringNotContainsString('first-visit sales', $html);
        $this->assertStringContainsString(self::HEADING, $html);
        $this->assertStringContainsString(self::LABEL, $html);
    }

    // The onboarding panel (the dashboard-adjacent ask on the
    // Finish screen): heading, label, and body render exactly
    // as the owner dictated, and the old framing is absent.
    public function test_onboarding_panel_renders_the_new_question(): void
    {
        $business = World::business(['average_ticket_cents' => null]);

        $page = $this->actingAs(World::owner($business))->get(route('onboarding.finish'));
        $page->assertOk();
        $page->assertSee(self::HEADING);
        $page->assertSee(self::BODY);
        $page->assertSee(self::LABEL, false);
        $page->assertSee('Your best guess is fine. You can change it any time in Settings, Value, and changing it never changes what you are charged.');
        $page->assertDontSee('What is a first visit worth?');
        $page->assertDontSee('First visit spend');
        $page->assertDontSee('spend on their first visit');
    }

    // Wording only: saving through Settings stores the same
    // cents as before, the estimate is still visits x the
    // stored figure, and no fee output moves.
    public function test_the_rename_changes_no_value_or_math(): void
    {
        $business = $this->businessWithVisits(3, ['average_ticket_cents' => null]);
        $owner = World::owner($business);

        $before = app(SettlementService::class)->accruedPreview($business->fresh());

        $this->actingAs($owner)->post(route('settings.value.update'), ['average_ticket' => '35'])
            ->assertSessionHas('status', 'Value saved.');
        $this->assertSame(3500, $business->fresh()->average_ticket_cents);

        $month = (new VisitValuation)->forMonth($business->fresh(), now());
        $this->assertSame(10500, $month['estimated_sales_cents'], '3 visits x $35 typical spend per visit');
        $this->assertSame(600, $month['fees_cents']);
        $this->assertSame($before, app(SettlementService::class)->accruedPreview($business->fresh()));
    }
}
