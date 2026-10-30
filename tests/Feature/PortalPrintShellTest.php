<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Portal print shell (BS-5; PM review 2026-10-27 item B6).
 * The portal print stylesheet lives in resources/css/app.css
 * behind the "Portal print shell" marker: one @media print
 * block, every rule scoped to .portal-theme. These pins hold
 * the block's contract at source level, the PortalRefreshTheme
 * pattern:
 *
 *  - the print palette re-points the swapped tokens (paper
 *    grounds, black text), so a portal page prints light in
 *    either device scheme;
 *  - the shell chrome is display:none in print (top bar,
 *    primary rail, settings subnav, notices, skip link,
 *    buttons);
 *  - the content measure is released and rows/panels hold
 *    together across page breaks;
 *  - the absence half: the block sets no global @page rule
 *    and never mentions the poster sheet, so the designed
 *    poster print block keeps its behavior byte for byte
 *    (its own pins are PosterPrintFitTest's).
 *
 * Rendered behavior is proven by the lane's print-emulation
 * captures (hidden_files/pm-oct27/captures/print-shell/),
 * not by this file: PHP cannot emulate print media.
 */
class PortalPrintShellTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /**
     * The portal print block's CODE: from its marker comment
     * through the end of its @media print block (the next
     * top-level section banner after the marker closes it),
     * with CSS comments stripped, so prose in the block's own
     * header (which names what the block must not do) can
     * never trip the absence pins below.
     */
    private function printBlock(string $css): string
    {
        $markerPos = strpos($css, 'Portal print shell');
        $this->assertNotFalse($markerPos, 'portal print marker missing from app.css');

        // Start at the @media rule itself, not at the marker:
        // the marker sits inside the header comment, and a
        // slice starting mid-comment cannot be comment-stripped.
        $blockStart = strpos($css, '@media print', (int) $markerPos);
        $this->assertNotFalse($blockStart, 'no @media print block after the portal print marker');

        $bannerPos = strpos($css, 'How-it-works scroll story', (int) $markerPos);
        $this->assertNotFalse($bannerPos, 'section after the portal print block missing');

        $block = substr($css, (int) $blockStart, (int) $bannerPos - (int) $blockStart);

        return (string) preg_replace('#/\*.*?\*/#s', '', $block);
    }

    public function test_the_print_block_repoints_the_swapped_tokens_to_the_print_palette(): void
    {
        $block = $this->printBlock($this->css());

        $this->assertStringContainsString('@media print', $block);
        $this->assertStringContainsString('.portal-theme {', $block);
        $this->assertStringContainsString('color-scheme: only light', $block);
        $this->assertStringContainsString('--color-surface-0: #ffffff', $block);
        $this->assertStringContainsString('--color-surface-1: #ffffff', $block);
        $this->assertStringContainsString('--color-text-1: #000000', $block);
        $this->assertStringContainsString('--color-text-2: #333333', $block);
        // Status meaning survives as words: white grounds,
        // black status text, for every status family.
        foreach (['success', 'warning', 'error', 'info'] as $family) {
            $this->assertStringContainsString("--color-{$family}-fill: #ffffff", $block, "print {$family} ground");
            $this->assertStringContainsString("--color-{$family}-text: #000000", $block, "print {$family} text");
        }
        $this->assertStringContainsString('--shadow-e1: none', $block);
    }

    public function test_the_print_block_hides_the_shell_chrome(): void
    {
        $block = $this->printBlock($this->css());

        foreach ([
            '.portal-theme header',
            '.portal-theme footer',
            ".portal-theme nav[aria-label='Primary']",
            ".portal-theme nav[aria-label='Settings']",
            ".portal-theme [aria-label='Notices']",
            '.portal-theme .skip-link',
            '.portal-theme button',
            '.portal-theme .btn',
            'display: none !important',
        ] as $needle) {
            $this->assertStringContainsString($needle, $block, "print chrome rule {$needle}");
        }
    }

    public function test_the_print_block_releases_the_measure_and_holds_rows_together(): void
    {
        $block = $this->printBlock($this->css());

        $this->assertStringContainsString('.portal-theme main', $block);
        $this->assertStringContainsString('max-width: none !important', $block);
        $this->assertStringContainsString('break-inside: avoid', $block);
        $this->assertStringContainsString('display: table-header-group', $block);
        // The sticky table column must not pin in print.
        $this->assertStringContainsString('.portal-theme .tbl-sticky', $block);
        $this->assertStringContainsString('position: static !important', $block);
    }

    public function test_the_print_block_never_touches_the_poster_pieces_or_the_paper(): void
    {
        $css = $this->css();
        $block = $this->printBlock($css);

        // No global @page in the portal block (the named poster
        // pages own their paper) and no poster selector: the
        // designed print pieces are out of this block's reach.
        $this->assertStringNotContainsString('@page', $block);
        $this->assertStringNotContainsString('.poster-sheet', $block);

        // The poster print block itself is intact elsewhere in
        // the file: its named pages and its exact-adjust rule.
        $this->assertStringContainsString('@page poster-window', $css);
        $this->assertStringContainsString('@page poster-counter', $css);
        $this->assertStringContainsString('print-color-adjust: exact', $css);
    }
}
