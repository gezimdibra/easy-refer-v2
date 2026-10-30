<?php

namespace Tests\Feature\Owner;

use App\Models\PaymentMethod;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * QR + Receipt train (Amendment 41): SA-06 dead-spot detection,
 * RC-3 Candidate A card surgery, RC-2 Variant 2 (the pair),
 * RC-3 Candidate F multi-up sheet, RC-3 Candidate E sheet
 * guidance. Rendering pins only; the geometry of the card and
 * the multi-up sheet ride PosterPrintFitTest's rig discipline
 * through the lane's capture proofs.
 */
class QrReceiptTrainTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $html, string $placement): string
    {
        preg_match('/data-placement="'.preg_quote($placement, '/').'".*?<\/li>/s', $html, $m);
        $this->assertNotEmpty($m, "No row for {$placement}.");

        return $m[0];
    }

    private function withCard($business): void
    {
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_qr_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_qr_'.$business->id,
        ]);
    }

    private function backdateCode($business, string $placement, Carbon $when): void
    {
        $code = QrCode::ensurePlacements($business)[$placement];
        $code->forceFill(['created_at' => $when])->save();
    }

    private function seedScanAt($business, string $placement, $membership, Carbon $when): void
    {
        Carbon::setTestNow($when);
        $code = QrCode::ensurePlacements($business)[$placement];
        ReferralEvent::record('scan', 'customer', $business, null, null, $membership,
            ['qr_code_id' => $code->id, 'placement' => $placement]);
        Carbon::setTestNow();
    }

    // ---------- SA-06 ----------

    public function test_rows_show_added_and_last_scan_dates_business_local(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business(['timezone' => 'America/New_York']);
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);
        // Added "today" in UTC terms is Oct 28 04:00 UTC; the
        // scan below lands Oct 20 02:30 UTC = Oct 19 local, so a
        // UTC render would name the wrong day.
        [$m1] = World::membership($business, World::customer('+12125550111'));
        $this->seedScanAt($business, 'counter', $m1, Carbon::parse('2026-10-20 02:30:00', 'UTC'));

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $counter = $this->row($html, 'counter');
        $this->assertStringContainsString('Added October 28, 2026', $counter);
        $this->assertStringContainsString('Last scan October 19, 2026', $counter);

        $window = $this->row($html, 'window');
        $this->assertStringContainsString('Added October 28, 2026', $window);
        $this->assertStringContainsString('No scans yet', $window);
    }

    public function test_nudge_absent_for_fresh_and_recently_scanned_spots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);
        // Window: 7 days old, zero scans. Counter: scan 3 days ago.
        $this->backdateCode($business, 'window', now()->subDays(7));
        [$m1] = World::membership($business, World::customer('+12125550111'));
        $this->seedScanAt($business, 'counter', $m1, now()->subDays(3));

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringNotContainsString('over a month', $html);
        $this->assertStringNotContainsString('Check the poster is up', $html);
        // Day-7 zero-scan reads differently from day-90: it has
        // its Added date and no nudge.
        $this->assertStringContainsString('No scans yet', $this->row($html, 'window'));
    }

    public function test_nudge_fires_at_31_days_silent_zero_scan_and_stale_scan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);
        // Window: created 90 days ago, never scanned.
        $this->backdateCode($business, 'window', now()->subDays(90));
        // Counter: code 40 days old, scanned once, 31 days ago.
        // (D-1 setup correction: the code is backdated so the
        // scan lands on THIS code. Without it the code was
        // created today and the 31-day-old scan could only be
        // a previous code's, the D-1 case, which must not fire
        // under the later-of clock. Assertions unchanged.)
        $this->backdateCode($business, 'counter', now()->subDays(40));
        [$m1] = World::membership($business, World::customer('+12125550111'));
        $this->seedScanAt($business, 'counter', $m1, now()->subDays(31));

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();

        $window = $this->row($html, 'window');
        $this->assertStringContainsString('No scans yet', $window);
        $this->assertStringContainsString('out since', $window);
        $this->assertStringContainsString('Check the poster is up where customers can see it.', $window);

        $counter = $this->row($html, 'counter');
        $this->assertStringContainsString('Last scan', $counter);
        $this->assertStringContainsString('Check the poster is still up where customers can see it.', $counter);

        // Receipt gets the hand-out wording, never the poster one.
        $this->backdateCode($business, 'receipt', now()->subDays(45));
        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $receipt = $this->row($html, 'receipt');
        $this->assertStringContainsString('Check the cards are by the register and one goes out with every receipt.', $receipt);
        $this->assertStringNotContainsString('Check the poster is up', $receipt);
    }

    public function test_replaced_code_dates_follow_the_placement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);
        $this->backdateCode($business, 'window', now()->subDays(90));

        $this->actingAs($owner)->post(route('owner.qr.placement.regenerate', ['placement' => 'window']))
            ->assertRedirect(route('owner.qr'));

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $window = $this->row($html, 'window');
        // The replacement was issued today: the row's Added date
        // is the replacement's, and the 90-day nudge is gone.
        $this->assertStringContainsString('Added October 28, 2026', $window);
        $this->assertStringNotContainsString('Check the poster is up', $window);
    }

    public function test_replaced_code_after_old_scan_shows_no_nudge_d1(): void
    {
        // D-1 (two-key review): staleness is the LATER of the
        // current code's Added date and the last scan, not the
        // last scan whenever one exists. A scan 35 days ago
        // under the PREVIOUS code must not accuse a code that
        // was replaced today: the new code has been silent for
        // one day, not over a month. The dates themselves still
        // render truthfully (Added = replacement, Last scan =
        // the old scan).
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);
        $this->backdateCode($business, 'window', now()->subDays(90));
        [$m1] = World::membership($business, World::customer('+12125550111'));
        $this->seedScanAt($business, 'window', $m1, now()->subDays(35));
        // seedScanAt clears the test clock; restore it so the
        // replacement is issued "today" (October 28).
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));

        $this->actingAs($owner)->post(route('owner.qr.placement.regenerate', ['placement' => 'window']))
            ->assertRedirect(route('owner.qr'));

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $window = $this->row($html, 'window');
        $this->assertStringContainsString('Added October 28, 2026', $window);
        $this->assertStringContainsString('Last scan September 23, 2026', $window);
        $this->assertStringNotContainsString('over a month', $window);
        $this->assertStringNotContainsString('Check the poster is still up', $window);
        $this->assertStringNotContainsString('Check the poster is up', $window);
    }

    public function test_nudge_still_fires_when_old_code_and_last_scan_both_over_30_days(): void
    {
        // Sibling of the D-1 pin: when BOTH the current code
        // (40 days old) and its last scan (31 days ago) are
        // over 30 days back, the later of the two is still
        // 31+ days silent, so the nudge still fires.
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);
        $this->backdateCode($business, 'counter', now()->subDays(40));
        [$m1] = World::membership($business, World::customer('+12125550111'));
        $this->seedScanAt($business, 'counter', $m1, now()->subDays(31));
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $counter = $this->row($html, 'counter');
        $this->assertStringContainsString('Added September 18, 2026', $counter);
        $this->assertStringContainsString('Last scan September 27, 2026', $counter);
        $this->assertStringContainsString('No scans in over a month.', $counter);
        $this->assertStringContainsString('Check the poster is still up where customers can see it.', $counter);
    }

    // ---------- RC-3 Candidate A: card surgery ----------

    public function test_receipt_card_names_the_business_and_tells_the_buyer_the_path(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers']);
        $owner = World::owner($business);
        World::qr($business);

        $html = $this->actingAs($owner)->get(route('owner.qr.poster', 'receipt'))->assertOk()->getContent();

        // Split card sheet from strip sheet at the strip's
        // named page (the CSS class name also occurs in <style>).
        $split = strpos($html, 'page: receipt-strip');
        $this->assertNotFalse($split);
        $cardHtml = substr($html, 0, $split);
        $stripHtml = substr($html, $split);

        $this->assertStringContainsString('Harbor Street Barbers', $cardHtml, 'The card sheet must name the business.');
        $this->assertStringContainsString('Scan this card, then share it with a friend.', $cardHtml);
        // The trust line funds the surgery on the CARD sheet only.
        $this->assertStringNotContainsString('No app to install.', $cardHtml);
        $this->assertStringContainsString('No app to install.', $stripHtml, 'The strip keeps its trust line.');
        $this->assertStringContainsString('Thanks for coming in.', $cardHtml);
    }

    public function test_other_sheets_keep_their_trust_line(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $counter = $this->actingAs($owner)->get(route('owner.qr.poster', 'counter'))->assertOk()->getContent();
        $this->assertStringContainsString('No app to install.', $counter);
    }

    // ---------- RC-2 Variant 2: the pair ----------

    public function test_start_here_pair_renders_only_with_both_reward_texts(): void
    {
        // OR-1 §1.3 A7 re-key (Amendment 49): the gate is now
        // REWARD SET (any configured state). The name is kept
        // for history; the body below pins the new gate in both
        // directions. (The SPLIT rendering above is unchanged.)
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringContainsString('Then hand out the receipt card', $html);
        $this->assertStringContainsString(
            'Print a stack of small cards and hand one out with every receipt. The card reaches every paying customer, including the ones who never look up at a poster, and it needs no wall space.',
            $html
        );
        $this->assertStringContainsString(
            'When your staff hand one out, one line is enough: This one is for a friend. If they come in, you both get a reward.',
            $html
        );
        $this->assertStringContainsString('Print the receipt card', $html);

        // Referrer text missing (UNIFIED): the block STAYS —
        // the referrer is promised the same reward by definition.
        // (unsetRelation: the test guard caches the user model,
        // whose business relation was loaded by the first GET.)
        $business->forceFill(['referrer_reward_text' => null, 'reward_split' => false])->save();
        $owner->unsetRelation('business');
        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringContainsString('Then hand out the receipt card', $html);

        // No reward set at all (UNSET): the pair block is gone whole.
        $business->forceFill(['friend_reward_text' => null])->save();
        $owner->unsetRelation('business');
        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Then hand out the receipt card', $html);
        $this->assertStringNotContainsString('you both get a reward', $html);
    }

    public function test_f10_unverifiable_line_is_replaced_everywhere(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'It stands by your register, where customers see it while they pay. It prints on half a sheet of paper (5.8 x 8.3 in). Once it is up, it keeps working on its own.',
            $html
        );
        $this->assertStringNotContainsString('the one most shops put up first', $html);
    }

    // ---------- RC-3 Candidate F: multi-up sheet ----------

    public function test_multi_up_sheet_renders_eight_shared_cards_with_cut_guides(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers']);
        $owner = World::owner($business);
        World::qr($business);

        $html = $this->actingAs($owner)
            ->get(route('owner.qr.poster', ['variant' => 'receipt', 'multi' => 1]))
            ->assertOk()->getContent();

        $this->assertSame(8, substr_count($html, 'Scan this card, then share it with a friend.'));
        $this->assertSame(8, substr_count($html, 'line-height: 14px">Harbor Street Barbers</p>'), 'Each of the 8 cards carries the business name line.');
        $this->assertStringContainsString('cut-guide', $html);
        // The single-card view is unchanged: two sheets, one card.
        $single = $this->actingAs($owner)->get(route('owner.qr.poster', 'receipt'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($single, 'Scan this card, then share it with a friend.'));
    }

    public function test_receipt_row_offers_the_sheet_of_cards(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->withCard($business);
        QrCode::ensurePlacements($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $receipt = $this->row($html, 'receipt');
        $this->assertStringContainsString('Print a sheet of cards', $receipt);
        $this->assertStringContainsString('multi=1', $receipt);
    }

    // ---------- RC-3 Candidate E: sheet guidance (screen only) ----------

    public function test_receipt_print_view_guides_the_two_sheets_in_screen_chrome(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $html = $this->actingAs($owner)->get(route('owner.qr.poster', 'receipt'))->assertOk()->getContent();
        $this->assertStringContainsString('The card: hand this out with the receipt.', $html);
        $this->assertStringContainsString('The strip: same card, sized for a receipt printer roll.', $html);
    }
}
