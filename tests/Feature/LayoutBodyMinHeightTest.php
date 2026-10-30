<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * U5 (launch-readiness MINOR 1): on a short page in the dark
 * scheme, the app layout showed a faint tone seam below the
 * content: <html> carries bg-surface-0 and <body> carries
 * bg-surface-1, and the body box ended at the content, leaving
 * the html tone (dark #161B22 against the #0E1116 ground) to
 * show underneath. The body now carries min-h-dvh, so the body
 * box always reaches the bottom of the viewport.
 *
 * Census of every layout for the identical mechanism (an
 * html/body token split whose body can end above the fold):
 * app (split: surface-0 over surface-1) and admin (the same
 * split) are the only two; auth, flow, marketing, and
 * onboarding each carry one identical token on both elements,
 * so no seam can form there and they are untouched. The pin
 * is census-style: it reads the layout files and fails if a
 * split layout ever loses the min-height class.
 */
class LayoutBodyMinHeightTest extends TestCase
{
    public function test_split_tone_layouts_carry_min_h_dvh_on_the_body(): void
    {
        foreach (['app', 'admin'] as $layout) {
            $blade = file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));

            $this->assertMatchesRegularExpression(
                '/<body class="[^"]*\bmin-h-dvh\b[^"]*"/',
                $blade,
                "layouts/{$layout}.blade.php body lost its min-h-dvh class"
            );
        }
    }

    public function test_single_tone_layouts_have_no_html_body_split_to_fix(): void
    {
        // The census verdict, pinned so a future token change
        // that introduces a split in one of these layouts is
        // caught here and re-censused instead of shipping a seam.
        foreach (['auth' => 'bg-surface-0', 'flow' => 'bg-surface-1', 'marketing' => 'bg-surface-0', 'onboarding' => 'bg-surface-0'] as $layout => $token) {
            $blade = file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
            $other = $token === 'bg-surface-0' ? 'bg-surface-1' : 'bg-surface-0';

            // The html element and the body carry the SAME single
            // surface token (marketing builds its html class
            // conditionally, but both branches use the body's
            // token), and the html line carries no second token
            // that would form a split.
            preg_match('/<html[^\n]*/', $blade, $htmlMatch);
            $htmlLine = $htmlMatch[0] ?? '';
            $this->assertStringContainsString($token, $htmlLine, "{$layout} html token changed; re-run the U5 census");
            $this->assertStringNotContainsString($other, $htmlLine, "{$layout} html gained a split token; re-run the U5 census");
            $this->assertMatchesRegularExpression('/<body class="'.preg_quote($token, '/').'/', $blade, "{$layout} body token changed; re-run the U5 census");
        }
    }
}
