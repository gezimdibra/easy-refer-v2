<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Release 40 (owner order, 2026-10-28 01:58 EDT; scope amendment
 * 02:01 EDT): the How It Works steps bar (.hiw-progress) is sticky
 * at top: 64px under the site header. The header hides on scroll
 * down by a transform (SL-11), which moves no layout, so the bar
 * used to stay parked 64px below the viewport top with an empty
 * band above it in the navbar-hidden state — the owner's phone
 * screenshot that ordered this release.
 *
 * The ONLY change: the bar's sticky top offset is a function of
 * the navbar's CURRENT visibility, consumed read-only:
 *   navbar visible -> top stays 64px (exactly as shipped);
 *   navbar hidden  -> top is 0, transitioning on the header's own
 *                     200ms cubic-bezier(0.2, 0, 0, 1).
 * The header's hide script, its triggers (y > 96, delta +/-4,
 * menu-open and focus guards), and the bar's own visibility are
 * frozen; the CSS keys off the header's existing
 * .site-header-hidden class via :has(), so there is no second
 * state that could drift from the navbar's actual state.
 *
 * Two layers:
 *  1. Source pins: the offset rule, its transition, the
 *     reduced-motion kill, and the frozen hide-script triggers.
 *  2. The named geometry pin (tests/Support/steps-bar-probe.mjs,
 *     WebKit rig, PosterPrintFitTest pattern): at 390x664,
 *     390x700, 390x844 and 1440x900 the bar's settled viewport top
 *     is the designed below-navbar value while the navbar is
 *     visible and 0 while it is hidden; during the hide
 *     transition the gap between the header's bottom edge and
 *     the bar's top never opens beyond the shipped border tuck;
 *     after a down-then-up settle there is no residual offset in
 *     either state. On a machine without the rig the geometry
 *     test skips rather than fails.
 */
class StepsBarHeaderSyncTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    private function layout(): string
    {
        return (string) file_get_contents(resource_path('views/layouts/marketing.blade.php'));
    }

    public function test_steps_bar_keeps_its_shipped_visible_state_offset(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.hiw-progress\s*\{\s*position:\s*sticky;\s*top:\s*64px;/s',
            $this->css(),
            'the steps bar base rule still sticks at 64px (navbar visible = exactly as today)'
        );
    }

    public function test_steps_bar_offset_follows_the_navbars_own_hidden_class(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/body:has\(>\s*#site-header\.site-header-hidden\)\s*\.hiw-progress\s*\{\s*top:\s*0(?:px)?\s*;/s',
            $css,
            'when the header carries its own hidden class, the steps bar top is 0'
        );
        $this->assertMatchesRegularExpression(
            '/\.hiw-progress\s*\{[^}]*transition:\s*top\s+200ms\s+cubic-bezier\(0\.2,\s*0,\s*0,\s*1\)/s',
            $css,
            'the bar top transitions on the header own 200ms duration and easing'
        );
    }

    public function test_steps_bar_offset_transition_is_killed_under_reduced_motion(): void
    {
        $css = $this->css();
        // The header's own reduced-motion block: the one that
        // kills the header's transition (a global reduce block
        // earlier in the file zeroes every transition too, but
        // the bar's kill belongs beside the header's).
        $anchor = strpos($css, '#site-header.site-header-hidden');
        $this->assertNotFalse($anchor, 'the header hidden rule exists');
        $start = strpos($css, '@media (prefers-reduced-motion: reduce)', $anchor);
        $this->assertNotFalse($start, 'the header reduced-motion block exists');
        $block = substr($css, $start, 400);

        $this->assertMatchesRegularExpression(
            '/\.hiw-progress\s*\{\s*transition:\s*none;/s',
            $block,
            'reduced motion: the steps bar top does not transition (the header never hides there anyway)'
        );
    }

    public function test_navbar_hide_triggers_are_the_frozen_shipped_ones(): void
    {
        $layout = $this->layout();

        // SL-11 as shipped: hide only past 96px on a >4px downward
        // step, never with the mobile menu open or focus inside;
        // return on a >4px upward step or back at <= 96px. The
        // amendment froze these verbatim — this release consumes
        // the resulting class and changes none of it.
        $this->assertStringContainsString('y > 96 && y > lastY + 4 && !menuOpen && !focusInside', $layout);
        $this->assertStringContainsString('y < lastY - 4 || y <= 96', $layout);
        $this->assertStringContainsString("header.classList.add('site-header-hidden')", $layout);
        $this->assertStringContainsString("header.classList.remove('site-header-hidden')", $layout);
    }

    public function test_home_renders_both_halves_of_the_coupling(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="site-header"', false);
        $response->assertSee('class="hiw-progress"', false);
        $response->assertSee('1 · Scan', false);
        $response->assertSee('4 · You pay per visit', false);
    }

    public function test_steps_bar_geometry_follows_navbar_visibility(): void
    {
        $probe = $this->probe();

        foreach (['390x664', '390x700', '390x844', '1440x900'] as $geom) {
            $this->assertArrayHasKey($geom, $probe, "No probe measurement for {$geom}.");
            $r = $probe[$geom];

            // Visible state: bar sits at the designed below-navbar
            // offset, tucked 1px under the header's bottom border
            // exactly as shipped (bar top = header bottom - 1).
            $this->assertEqualsWithDelta(
                $r['visible']['headerBottom'] - 1,
                $r['visible']['barTop'],
                1.5,
                "[{$geom}] navbar visible: bar top {$r['visible']['barTop']} is not the designed below-navbar value (header bottom {$r['visible']['headerBottom']})"
            );
            // Hidden state: bar at the very top, as if the navbar
            // did not exist. This is the assertion whose absence
            // shipped the defect: pre-fix it measured 64.
            $this->assertEqualsWithDelta(
                0.0,
                (float) $r['hidden']['barTop'],
                1.5,
                "[{$geom}] navbar hidden: bar top {$r['hidden']['barTop']} leaves a gap above the steps bar"
            );
            $this->assertTrue($r['hidden']['hidden'], "[{$geom}] header was not in its hidden state for the hidden measurement");
            // Settle: after scrolling down then back up and down
            // again, neither state carries a residual offset.
            $this->assertEqualsWithDelta(
                0.0,
                (float) $r['hiddenAgain']['barTop'],
                1.5,
                "[{$geom}] settle: re-hidden bar top {$r['hiddenAgain']['barTop']} is not 0"
            );
            // Coupling: sampled frame-by-frame (rAF) through the
            // hide transition, the band between the header's
            // bottom edge and the bar's top stays within 4px of
            // the shipped -1px tuck at every painted frame
            // (pre-fix the same sampling walked to +64 and stayed;
            // the build's rAF record is -2..+2.6px).
            foreach ($r['hideSamples'] as $s) {
                $gap = $s['barTop'] - $s['headerBottom'];
                $this->assertGreaterThanOrEqual(
                    -4.0,
                    $gap,
                    "[{$geom}] mid-transition the bar overtook the header by {$gap}px at t={$s['t']}ms"
                );
                $this->assertLessThanOrEqual(
                    4.0,
                    $gap,
                    "[{$geom}] mid-transition gap opened to {$gap}px at t={$s['t']}ms"
                );
            }
        }
    }

    private static ?array $probeCache = null;

    private function probe(): array
    {
        if (self::$probeCache !== null) {
            return self::$probeCache;
        }

        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; steps-bar geometry is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; steps-bar geometry is unmeasured on this machine.');
        }

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        if ($cssFile === null) {
            $this->markTestSkipped('Build manifest has no app stylesheet; steps-bar geometry is unmeasured.');
        }
        $cssLink = '<link rel="stylesheet" href="/build/'.$cssFile.'">';

        $dir = sys_get_temp_dir().'/steps-bar-'.getmypid();
        @mkdir($dir, 0777, true);
        $html = $this->get('/')->assertOk()->getContent();
        $origin = rtrim((string) config('app.url'), '/');
        $html = str_replace($origin, '', $html);
        $html = str_replace('</head>', $cssLink.'</head>', $html);
        file_put_contents($dir.'/home.html', $html);

        $config = $dir.'/config.json';
        file_put_contents($config, json_encode([
            'pagesDir' => $dir,
            'publicDir' => public_path(),
            'rigDir' => $rig,
        ]));

        $cmd = 'PLAYWRIGHT_BROWSERS_PATH='.escapeshellarg($rig.'/browsers')
            .' timeout 300 '.$node.' '.escapeshellarg(__DIR__.'/../Support/steps-bar-probe.mjs')
            .' '.escapeshellarg($config).' 2>/dev/null';
        $json = (string) shell_exec($cmd);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->fail('Steps-bar probe produced no measurement. Command: '.$cmd);
        }

        return self::$probeCache = $decoded;
    }
}
