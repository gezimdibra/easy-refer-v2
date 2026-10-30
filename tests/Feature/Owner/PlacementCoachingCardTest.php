<?php

namespace Tests\Feature\Owner;

use App\Models\ActionCenterDismissal;
use App\Models\Business;
use App\Models\PlacementCoachingEvent;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-04 placement coaching, Variant B (design pass Sections 3,
 * 5, 8): the one card on the QR page above Print by spot. It
 * renders only when the full floor holds; below the floor the
 * page carries no coaching string at all (absence-pinned in
 * both directions, per the verification standard).
 */
class PlacementCoachingCardTest extends TestCase
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

    private function businessWithAges(array $ages): Business
    {
        $business = World::business();
        $codes = QrCode::ensurePlacements($business);
        foreach ($ages as $placement => $days) {
            $codes[$placement]->forceFill(['created_at' => $this->now->copy()->subDays($days)])->save();
        }

        return $business->fresh();
    }

    private function seedScans(Business $business, string $placement, int $count, int $daysAgo): void
    {
        for ($i = 0; $i < $count; $i++) {
            Carbon::setTestNow($this->now->copy()->subDays($daysAgo)->addMinutes($i));
            ReferralEvent::record('scan', 'customer', $business, null, null, null, ['placement' => $placement]);
        }
        Carbon::setTestNow($this->now);
    }

    /** Window 12 in W, counter 2 in W, every code 60 days old. */
    private function firingBusiness(): Business
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'counter', 2, 5);
        $this->seedScans($business, 'table', 5, 5);
        $this->seedScans($business, 'receipt', 5, 5);

        return $business;
    }

    private function row(string $html, string $placement): string
    {
        preg_match('/data-placement="'.preg_quote($placement, '/').'".*?<\/li>/s', $html, $m);

        return $m[0] ?? '';
    }

    private function qrHtml(Business $business): string
    {
        return $this->actingAs(World::owner($business))->get(route('owner.qr'))->assertOk()->getContent();
    }

    // ---------- The card ----------

    public function test_card_renders_above_print_by_spot_with_exact_copy_and_action(): void
    {
        $business = $this->firingBusiness();
        $html = $this->qrHtml($business);

        $this->assertStringContainsString('One spot is bringing in more scans', $html);
        $this->assertStringContainsString(
            'Window poster: 12 scans in the last 4 weeks. Counter poster: 2. Try a stack of receipt cards by the register, handed out with every receipt.',
            $html
        );
        $this->assertStringContainsString('Print a sheet of cards', $html);
        $this->assertStringContainsString('Not now', $html);

        // The card's action is the move's print action: the
        // receipt sheet (print=1, multi=1) on the shipped route.
        $cardStart = strpos($html, 'id="placement-coaching"');
        $this->assertNotFalse($cardStart);
        $card = substr($html, $cardStart, strpos($html, 'Print by spot') - $cardStart);
        $this->assertStringContainsString(
            str_replace('&', '&amp;', route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1, 'multi' => 1])),
            $card
        );

        // Placement: above Print by spot, below the share strip.
        $this->assertLessThan(strpos($html, 'Print by spot'), $cardStart);
        $this->assertGreaterThan(strpos($html, 'Share your code online'), $cardStart);

        // The impression is recorded with the fingerprint.
        $impressions = PlacementCoachingEvent::query()
            ->where('business_id', $business->id)->where('kind', 'impression')->get();
        $this->assertCount(1, $impressions);
        $this->assertSame('coach|counter|window|receipt_cards|10', $impressions->sole()->fingerprint);
    }

    public function test_card_names_the_current_display_names_after_a_rename(): void
    {
        $business = $this->firingBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.qr.placement.rename', ['placement' => 'counter']), ['name' => 'Register sign'])
            ->assertRedirect(route('owner.qr'));

        $html = $this->qrHtml($business);
        $this->assertStringContainsString('Window poster: 12 scans in the last 4 weeks. Register sign: 2.', $html);
        $this->assertStringNotContainsString('Counter poster: 2.', $html);
    }

    public function test_move_sentences_render_per_losing_placement(): void
    {
        $cases = [
            'window' => ['Try a counter poster by the register, where every paying customer stands.', 'Print the counter poster', 'counter'],
            'table' => ['Try a counter poster by the register, where every paying customer stands.', 'Print the counter poster', 'counter'],
            'receipt' => ['Try keeping the card stack right by the register, and hand one out with every receipt.', 'Print a sheet of cards', 'receipt'],
        ];

        foreach ($cases as $worst => [$move, $action, $variant]) {
            $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
            $best = $worst === 'window' ? 'counter' : 'window';
            $this->seedScans($business, $best, 12, 5);
            $this->seedScans($business, $worst, 2, 5);
            foreach (['counter', 'window', 'table', 'receipt'] as $placement) {
                if ($placement !== $best && $placement !== $worst) {
                    $this->seedScans($business, $placement, 5, 5);
                }
            }

            $html = $this->qrHtml($business);
            $this->assertStringContainsString($move, $html, "Move for worst={$worst}");
            $cardStart = strpos($html, 'id="placement-coaching"');
            $card = substr($html, $cardStart, strpos($html, 'Print by spot') - $cardStart);
            $this->assertStringContainsString($action, $card);
            $expectedRoute = $variant === 'receipt'
                ? route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1, 'multi' => 1])
                : route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]);
            $this->assertStringContainsString(str_replace('&', '&amp;', $expectedRoute), $card);
        }
    }

    public function test_a_single_scan_renders_the_singular(): void
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'receipt', 1, 5);
        $this->seedScans($business, 'counter', 5, 5);
        $this->seedScans($business, 'table', 5, 5);

        $html = $this->qrHtml($business);
        $this->assertStringContainsString('Receipt card: 1 scan.', $html);
        $this->assertStringNotContainsString('Receipt card: 1.', $html);
    }

    // ---------- Below the floor: nothing renders ----------

    public function test_no_coaching_string_renders_below_the_floor(): void
    {
        $scenarios = [
            'fresh business, no scans' => $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]),
            'codes too young' => $this->businessWithAges(['counter' => 10, 'window' => 10, 'table' => 10, 'receipt' => 10]),
        ];

        $weak = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($weak, 'window', 7, 5);
        $this->seedScans($weak, 'counter', 1, 5);
        $scenarios['best under eight'] = $weak;

        $inactive = $this->firingBusiness();
        $inactive->forceFill(['friend_reward_text' => null])->save();
        $scenarios['flow inactive'] = $inactive->fresh();

        foreach ($scenarios as $label => $business) {
            $html = $this->qrHtml($business);
            $this->assertStringNotContainsString('placement-coaching', $html, $label);
            $this->assertStringNotContainsString('One spot is bringing in more scans', $html, $label);
            $this->assertStringNotContainsString('in the last 4 weeks', $html, $label);
            $this->assertStringNotContainsString('Try a counter poster by the register', $html, $label);
            $this->assertStringNotContainsString('Try a stack of receipt cards', $html, $label);
            $this->assertStringNotContainsString('Try keeping the card stack', $html, $label);
            // The standing page is untouched: rows still render.
            $this->assertStringContainsString('Print by spot', $html, $label);
            $this->assertStringContainsString('Totals are all time.', $html, $label);
        }

        $this->assertSame(0, PlacementCoachingEvent::query()->count(), 'No impression is recorded below the floor.');
    }

    // ---------- Disjointness with SA-06 (one voice per spot) ----------

    public function test_zero_loser_belongs_to_sa06_never_to_coaching(): void
    {
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'counter', 5, 5);
        $this->seedScans($business, 'table', 5, 5);
        // Receipt: 0 scans ever, code 60 days old -> SA-06 stale.

        $html = $this->qrHtml($business);
        $this->assertStringNotContainsString('placement-coaching', $html);
        $receipt = $this->row($html, 'receipt');
        $this->assertStringContainsString('No scans in over a month.', $receipt);
        $this->assertStringContainsString('Check the cards are by the register', $receipt);
    }

    public function test_boundary_29_day_loser_gets_neither_voice(): void
    {
        // Design Section 1.6 boundary: last scan 29 days ago is
        // outside the coaching window and inside SA-06's 30
        // days, so the row renders plain for a day.
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'counter', 2, 29);
        $this->seedScans($business, 'table', 5, 5);
        $this->seedScans($business, 'receipt', 5, 5);

        $html = $this->qrHtml($business);
        $this->assertStringNotContainsString('placement-coaching', $html);
        $counter = $this->row($html, 'counter');
        $this->assertStringContainsString('Last scan', $counter);
        $this->assertStringNotContainsString('over a month', $counter);
    }

    public function test_a_stale_spot_stands_the_whole_card_down(): void
    {
        // The decision table's consequence (Sections 4.1 + 1.6):
        // a stale spot has 0 scans in the window, so it IS the
        // unique minimum, the (best, worst) pair fails F5, and
        // the decision stops: no fall-through to the next pair.
        // The stale spot's only voice is SA-06's nudge, and a
        // rendering card therefore never coexists with any nudge.
        $business = $this->businessWithAges(['counter' => 60, 'window' => 60, 'table' => 60, 'receipt' => 60]);
        $this->seedScans($business, 'window', 12, 5);
        $this->seedScans($business, 'counter', 2, 5);
        $this->seedScans($business, 'table', 5, 5);
        // Receipt stale at 0 in the window.
        $html = $this->qrHtml($business);

        $this->assertStringNotContainsString('placement-coaching', $html);
        $receipt = $this->row($html, 'receipt');
        $this->assertStringContainsString('No scans in over a month.', $receipt);
        $counter = $this->row($html, 'counter');
        $this->assertStringNotContainsString('over a month', $counter);
    }

    // ---------- Dismissal, acted, lifecycle over HTTP ----------

    public function test_dismiss_hides_the_card_and_records_the_fingerprint(): void
    {
        $business = $this->firingBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.qr.coaching.dismiss'))
            ->assertRedirect(route('owner.qr'));

        $dismissal = ActionCenterDismissal::query()
            ->where('business_id', $business->id)->where('item_key', 'placement_coaching')->sole();
        $this->assertSame('coach|counter|window|receipt_cards|10', $dismissal->state_fingerprint);
        $this->assertSame(1, PlacementCoachingEvent::query()
            ->where('business_id', $business->id)->where('kind', 'dismissed')->count());

        $html = $this->qrHtml($business);
        $this->assertStringNotContainsString('placement-coaching', $html);
    }

    public function test_dismiss_with_no_live_card_records_nothing(): void
    {
        $business = World::business();
        QrCode::ensurePlacements($business);
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.qr.coaching.dismiss'))
            ->assertRedirect(route('owner.qr'));

        $this->assertSame(0, PlacementCoachingEvent::query()->count());
        $this->assertSame(0, ActionCenterDismissal::query()->count());
    }

    public function test_printing_the_suggested_sheet_through_the_card_action_suppresses_the_card(): void
    {
        $business = $this->firingBusiness();
        $owner = World::owner($business);

        $this->qrHtml($business); // impression
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1, 'multi' => 1]))
            ->assertOk();

        $html = $this->qrHtml($business);
        $this->assertStringNotContainsString('placement-coaching', $html);
    }

    public function test_replacing_the_coached_code_resolves_the_card_by_eligibility(): void
    {
        $business = $this->firingBusiness();
        $owner = World::owner($business);

        $this->qrHtml($business);
        $this->actingAs($owner)->post(route('owner.qr.placement.regenerate', ['placement' => 'counter']))
            ->assertRedirect(route('owner.qr'));

        $html = $this->qrHtml($business);
        $this->assertStringNotContainsString('placement-coaching', $html);
    }

    public function test_impressions_dedupe_per_day_over_http(): void
    {
        $business = $this->firingBusiness();

        $this->qrHtml($business);
        $this->qrHtml($business);
        $this->assertSame(1, PlacementCoachingEvent::query()
            ->where('business_id', $business->id)->where('kind', 'impression')->count());

        Carbon::setTestNow($this->now->copy()->addDay());
        $this->qrHtml($business);
        $this->assertSame(2, PlacementCoachingEvent::query()
            ->where('business_id', $business->id)->where('kind', 'impression')->count());
    }
}
