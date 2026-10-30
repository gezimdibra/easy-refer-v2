<?php

namespace Tests\Feature\Owner;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * P3 poster mix (owner pick 2026-10-25: Variant A's "Start here"
 * plus Variant B's "By spot"; mix.md + benchmark pins M-1..M-5).
 * Copy is Amendment 27 (MIX-6, entered before this code): the
 * strings below are transcribed verbatim from the approved mix
 * mock's surgery, and the word budget (M-4) is part of the
 * amendment. The FE-LBL-01 footnote slot ("Totals are all time.",
 * Amendment 25) survives in the by-spot panel; QR-INACTIVE-01
 * behavior on this page is untouched.
 */
class QrPosterMixTest extends TestCase
{
    use RefreshDatabase;

    private function qrHtml(Business $business): string
    {
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        return $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
    }

    private function withCard(Business $business): void
    {
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_mix_1',
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_mix_'.$business->id,
        ]);
    }

    /** The by-spot row HTML for one placement. */
    private function row(string $html, string $placement): string
    {
        $this->assertMatchesRegularExpression(
            '/data-placement="'.preg_quote($placement, '/').'"/',
            $html,
            "The by-spot panel has no row for {$placement}."
        );
        preg_match('/data-placement="'.preg_quote($placement, '/').'".*?<\/li>/s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_start_here_panel_is_the_recommendation_with_one_primary(): void
    {
        $html = $this->qrHtml(World::business());

        $this->assertStringContainsString('Start here: your first poster', $html);
        $this->assertStringContainsString('The counter poster', $html);
        // Exactly three sentences, verbatim (M-4); Amendment 31
        // [MIX-04] adds the counter poster's real print size.
        $this->assertStringContainsString(
            'It stands by your register, where customers see it while they pay. It prints on half a sheet of paper (5.8 x 8.3 in). Once it is up, it keeps working on its own.',
            $html
        );
        $this->assertStringContainsString('Print this poster', $html);

        // Exactly ONE primary button on the whole page (the mix
        // section's single primary action).
        $this->assertSame(1, substr_count($html, 'btn-primary'), 'The QR page must carry exactly one primary button.');

        // The preview renders from the real counter poster view
        // (MIX-1 decision: scaled live sheet render, preview mode).
        $this->assertStringContainsString('poster-preview', $html);
        $this->assertStringContainsString(
            route('owner.qr.poster', ['variant' => 'counter', 'preview' => 1]),
            $html
        );
    }

    public function test_m3_start_here_print_link_is_the_counter_row_print_link(): void
    {
        $html = $this->qrHtml(World::business());

        preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*(?:<[^>]+>\s*)*Print this poster/', $html, $start);
        $this->assertNotEmpty($start, 'Start here print link not found.');

        $counter = $this->row($html, 'counter');
        preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*(?:<[^>]+>\s*)*Print the counter poster/', $counter, $rowLink);
        $this->assertNotEmpty($rowLink, 'Counter row print link not found.');

        $this->assertSame($start[1], $rowLink[1], 'M-3: both counter print links must be the identical URL.');
    }

    public function test_share_strip_keeps_the_shipped_tools(): void
    {
        $business = World::business();
        $html = $this->qrHtml($business);

        $this->assertStringContainsString('Share your code online', $html);
        $this->assertStringContainsString(
            'Your sign-up link, to paste anywhere. Or download just the code image, for your own designs.',
            $html
        );
        $this->assertStringContainsString('Copy link', $html);
        $this->assertStringContainsString('Download code only (for your own designs)', $html);
        $this->assertStringContainsString(route('owner.qr.download'), $html);
    }

    public function test_by_spot_rows_carry_stats_without_the_added_date(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $codes = QrCode::ensurePlacements($business);

        [$m1] = World::membership($business, World::customer('+12125550111'));
        [$m2] = World::membership($business, World::customer('+12125550222'));
        ReferralEvent::record('scan', 'customer', $business, null, null, $m1, ['qr_code_id' => $codes['table']->id, 'placement' => 'table']);
        ReferralEvent::record('scan', 'customer', $business, null, null, $m1, ['qr_code_id' => $codes['table']->id, 'placement' => 'table']);
        ReferralEvent::record('scan', 'customer', $business, null, null, $m2, ['qr_code_id' => $codes['table']->id, 'placement' => 'table']);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();

        $this->assertStringContainsString('Print by spot', $html);
        $this->assertStringContainsString(
            'Every spot gets its own code, so you can see which spot brings people in.',
            $html
        );

        // [MIX-04] (Amendment 31): each spot line states where
        // it goes and its real print size.
        $table = $this->row($html, 'table');
        $this->assertStringContainsString('3 scans · 2 joins so far', $table);
        $this->assertStringContainsString('Standing up on your tables. The sheet is 8.3 x 5.8 in and folds in half to stand.', $table);
        $this->assertStringContainsString('Print the table tent', $table);

        $counter = $this->row($html, 'counter');
        $this->assertStringContainsString('By the register, where people pay. Prints on half a sheet of paper (5.8 x 8.3 in).', $counter);
        $this->assertStringContainsString('Print the counter poster', $counter);

        $window = $this->row($html, 'window');
        $this->assertStringContainsString('In the front window, facing out. A full sheet of paper (8.3 x 11.7 in), so people can read it from the sidewalk.', $window);
        $this->assertStringContainsString('Print the window poster', $window);

        $receipt = $this->row($html, 'receipt');
        $this->assertStringContainsString('Handed out with the receipt. A small card, 3.3 x 2.2 in, the size of a business card.', $receipt);
        $this->assertStringContainsString('Print the receipt card', $receipt);

        // SA-06 (Amendment 41, superseding the S4 removal): the
        // Added date renders on every row, business-local, with
        // the last-scan date; pinned in full in QrReceiptTrainTest.
        $this->assertStringContainsString('Added ', $html);

        // Per-placement print hrefs are pairwise distinct.
        $hrefs = [];
        foreach (['counter', 'window', 'table', 'receipt'] as $placement) {
            preg_match_all('/href="([^"]*poster[^"]*)"/', $this->row($html, $placement), $mm);
            $hrefs[$placement] = $mm[1][0] ?? null;
        }
        $this->assertSame(4, count(array_unique(array_values($hrefs))), 'Each spot must print its own poster.');

        // FE-LBL-01 footnote slot survives in the by-spot panel.
        $this->assertStringContainsString('Totals are all time.', $html);
    }

    public function test_m2_replace_is_a_real_button_and_the_modals_are_not_duplicated(): void
    {
        $html = $this->qrHtml(World::business());

        // M-2: Replace stays a real <button> (the confirm-modal
        // trigger contract renders a caller-supplied button).
        $this->assertMatchesRegularExpression(
            '/<button type="button"[^>]*>\s*Replace code\s*<\/button>/',
            $html
        );
        $this->assertSame(
            4,
            preg_match_all('/<button type="button"[^>]*underline[^>]*>\s*Replace code\s*<\/button>/', $html),
            'Each spot row carries exactly one text-styled Replace button.'
        );

        // Exactly four per-placement modals, no duplicates (F-6
        // count re-derived at build: four placements, one modal
        // each, plus the separate replace-all danger modal).
        foreach (['counter', 'window', 'table', 'receipt'] as $placement) {
            // The component renders the modal id as its dialog's
            // aria-labelledby target ("{id}-title").
            $this->assertSame(1, substr_count($html, 'aria-labelledby="replace-'.$placement.'-title"'), "Exactly one replace modal for {$placement}.");
        }
        // [ON-SPOT-3] (Amendment 31, superseding the
        // REF-POSTER-03b name forms): the modal title is the
        // same for every code and its body reads the code's
        // current display name (here the window default).
        $this->assertStringContainsString('Replace this code?', $html);
        $this->assertStringContainsString(
            'Printed copies of the Window poster code will stop working right away. Your other codes keep working.',
            $html
        );
    }

    public function test_also_available_rows_are_quiet_text_rows(): void
    {
        $html = $this->qrHtml(World::business());

        $this->assertStringContainsString('Also available', $html);
        // [ON-QR-1] (Amendment 31): the Screen poster row is
        // retired; its name and gloss render nowhere (pinned in
        // full in QrSpotRenameTest). The Mini-poster row stays,
        // with its [MIX-04] size line.
        $this->assertStringNotContainsString('Screen poster', $html);
        $this->assertStringNotContainsString('For a tablet or TV screen', $html);
        $this->assertStringContainsString('Mini-poster', $html);
        $this->assertStringContainsString('A smaller counter poster, for tight spaces. Prints at 4.1 x 5.8 in.', $html);
        $this->assertStringNotContainsString(route('owner.qr.poster', ['variant' => 'screen', 'print' => 1]), $html);
        $this->assertStringContainsString(route('owner.qr.poster', ['variant' => 'mini', 'print' => 1]), $html);
    }

    public function test_mix5_removals_are_absent(): void
    {
        $html = $this->qrHtml(World::business());

        $this->assertStringNotContainsString('id="posters"', $html);
        $this->assertStringNotContainsString('id="placements"', $html);
        $this->assertStringNotContainsString('Your codes by placement', $html);
        $this->assertStringNotContainsString('For the counter, A5', $html);
        $this->assertStringNotContainsString('For a screen or tablet', $html);
        $this->assertStringNotContainsString('Table tent, two faces', $html);
        $this->assertStringNotContainsString('Print poster', $html);
        $this->assertStringNotContainsString('Print counter poster', $html);
        $this->assertStringNotContainsString('Print mini-poster', $html);
        $this->assertStringNotContainsString('Scans and joins count customers who joined through that code.', $html);
        // The sections below the mix are unchanged.
        $this->assertStringContainsString('How redemption works', $html);
        $this->assertStringContainsString('Replace all codes', $html);
    }

    public function test_m4_word_budget_for_the_poster_region(): void
    {
        $business = World::business();
        $this->withCard($business);
        $html = $this->qrHtml($business);

        // The mix-stats instrument: visible text from the page H1
        // up to (not including) "How redemption works". Hidden
        // modal dialogs (x-cloak) are not visible text, exactly
        // as the mock's innerText instrument treated them.
        $start = strpos($html, '<h1');
        $end = strpos($html, 'How redemption works');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $region = substr($html, $start, $end - $start);
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<div>'.$region.'</div>');
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//*[@x-cloak] | //script | //style')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $text = $dom->textContent;
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        // M-4 MOVED by Amendment 31 (2026-10-27): the 240-word
        // cap was written for the Amendment 27 mix copy. The
        // ratified Amendment 31 strings are verbatim and longer
        // (the [ON-SPOT-1] intro alone adds three sentences;
        // [MIX-04] adds a print size to every line), and the
        // region now measures 281 words with them, partly
        // offset by [ON-QR-1] removing the Screen poster row.
        // The cap moves to 285 (measured 281 + 4 headroom) so
        // it still guards against further bloat; the inventory's
        // M-4 line needs the matching addendum (flagged in the
        // lane report).
        // M-4 MOVED AGAIN by Amendment 41 (2026-10-28, QR +
        // receipt train): the region now also carries the RC-2
        // pair block (about 61 words, both-texts state as here),
        // SA-06's Added date on all four rows (12 words), and
        // the receipt row's sheet-of-cards action (5 words),
        // and measures 372 words in this state. The cap moves
        // to 376 (measured 372 + 4 headroom), derivation
        // recorded in Amendment 41.
        // M-4 MOVED AGAIN by Amendment 53 (2026-10-29, ID-11
        // coupons): the measured region now also carries the
        // coupon tabs (3 words: "Referral codes" + "Coupons"),
        // the [HINT-COUP-01] benefit hint line (16 words), and
        // its Dismiss action (1 word), and measures 392 words
        // in this state. The cap moves to 396 (measured 392 +
        // 4 headroom), derivation recorded in Amendment 53,
        // which names this move as part of the train.
        $this->assertLessThanOrEqual(396, count($words), 'M-4: the poster region must stay at or under 396 visible words; got '.count($words).'.');
    }
}
