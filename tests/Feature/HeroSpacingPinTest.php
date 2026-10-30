<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Release 38 Revision 2 (owner clarification, 2026-10-27 21:04 EDT):
 * hero spacing lives in TWO stacks and both are pinned here.
 *
 * STANDARD stack (viewport height > 720px): reverted FULLY to the
 * pre-r34 values the owner approved — trust-row margin 24px
 * (mt-6 on .mkt-hero-trust in home.blade.php), stage margin 32px
 * in the mobile block / 56px in the base rule. Release 34's
 * 40/48/80 and release 38's first-lane 32/40/64 midpoint values
 * must never come back.
 *
 * COMPACTED stack (@media (max-width: 1023.98px) nesting
 * @media (max-height: 720px)): this is the stack the owner's
 * iPhone 13 actually renders — Safari exposes <= 720px viewport
 * height at load (forensic record:
 * hidden_files/deploy34/capture-divergence-diagnosis.md). Its
 * trust/stage margins were 4px/4px through release 37; Revision 2
 * raises them to the standard band, 24px / 32px, inside the
 * R1-CLEARANCE headroom (clearance = consent-card top minus
 * hero-visual top, floor 40px at 375x667; measured 169px before
 * at 375x667, 166px at 390x664, 202px at 390x700, so the +48px
 * step leaves >= 118px). The zone's title/lede values are NOT
 * part of this change and stay pinned at their compact values.
 *
 * The pins are source-level on purpose: the PHPUnit harness has
 * no rendered-geometry pin for this zone (the clearance checks
 * live in the WebKit measure recipes, hidden_files/redesign/08d
 * and 08e), so these declarations are pinned where they are
 * written, scoped to their own media-query block.
 */
class HeroSpacingPinTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /** The compacted zone: from its media query to the M-02 block that follows it. */
    private function compactBlock(string $css): string
    {
        $start = strpos($css, '@media (max-height: 720px)');
        $this->assertNotFalse($start, 'the short-viewport compacted zone exists');
        $end = strpos($css, 'M-02 sticky action bar', $start);
        $this->assertNotFalse($end, 'the compacted zone still ends at the M-02 block');

        return substr($css, $start, $end - $start);
    }

    private function stageMargin(string $block): ?string
    {
        if (preg_match('/\.mkt-hero-stage\s*\{(?:[^{}]*?)margin-top:\s*(\d+)px/s', $block, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Every .mkt-hero-stage margin in the stylesheet, in source
     * order: base rule, mobile standard block, compacted zone.
     * (Other components carry their own max-width blocks earlier
     * in the file, so the stage rules are censused by selector,
     * not by slicing at the first media query.)
     */
    private function allStageMargins(string $css): array
    {
        preg_match_all('/\.mkt-hero-stage\s*\{(?:[^{}]*?)margin-top:\s*(\d+)px/s', $css, $m);

        return $m[1];
    }

    public function test_home_trust_row_renders_with_the_pre_r34_margin_class(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('mkt-hero-trust mt-6', false);
        $response->assertDontSee('mkt-hero-trust mt-8', false);
        $response->assertDontSee('mkt-hero-trust mt-10', false);
    }

    public function test_standard_stage_margins_are_the_pre_r34_values(): void
    {
        // Source order: base (desktop) 56, mobile standard 32,
        // compacted zone 32 (pinned in its own test below). No
        // r34 (80/48) or first-lane r38 (64/40) stage value
        // survives anywhere.
        $this->assertSame(
            ['56', '32', '32'],
            $this->allStageMargins($this->css()),
            'stage margins in source order are base 56 / mobile 32 / compact 32'
        );
    }

    public function test_compact_stack_carries_the_raised_band(): void
    {
        $compact = $this->compactBlock($this->css());

        $this->assertMatchesRegularExpression(
            '/\.mkt-hero \.mkt-hero-trust\s*\{\s*margin-top:\s*24px/',
            $compact,
            'compact trust margin is the raised 24px'
        );
        $this->assertSame('32', $this->stageMargin($compact), 'compact stage margin is the raised 32px');

        // The 4px payback values are gone from the two spacing
        // declarations this release owns.
        $this->assertDoesNotMatchRegularExpression(
            '/\.mkt-hero \.mkt-hero-trust\s*\{\s*margin-top:\s*4px/',
            $compact,
            'compact trust margin is no longer 4px'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.mkt-hero-stage\s*\{(?:[^{}]*?)margin-top:\s*4px/s',
            $compact,
            'compact stage margin is no longer 4px'
        );
    }

    public function test_compact_title_and_lede_values_are_unchanged(): void
    {
        $compact = $this->compactBlock($this->css());

        $this->assertMatchesRegularExpression(
            '/\.mkt-hero \.mkt-hero-title\s*\{\s*font-size:\s*28px;\s*line-height:\s*32px/',
            $compact,
            'compact title stays 28px/32px'
        );
        $this->assertMatchesRegularExpression(
            '/\.mkt-hero \.mkt-hero-lede\s*\{\s*margin-top:\s*12px;\s*font-size:\s*17px;\s*line-height:\s*26px/',
            $compact,
            'compact lede stays at its shipped values'
        );
    }
}
