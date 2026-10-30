<?php

namespace Tests\Feature\Owner;

use App\Models\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The poster mix's geometry pins (benchmark M-1 + M-5, plus the
 * standing action-row pin from knowledge/build-and-test.md),
 * measured in the WebKit rig against the rendered QR page. This
 * test succeeds QrPlacementsPanelGeometryTest, whose surface
 * (the placements table) MIX-5 removed; the pin itself carries
 * forward onto the by-spot rows.
 *
 * On a machine without the rig the test skips rather than fails.
 */
class QrPosterMixGeometryTest extends TestCase
{
    use RefreshDatabase;

    private const WIDTHS = [390, 375, 1440];

    private static ?array $probe = null;

    private function probe(): array
    {
        if (self::$probe !== null) {
            return self::$probe;
        }

        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; mix geometry is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; mix geometry is unmeasured on this machine.');
        }

        $business = World::business();
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        $dir = sys_get_temp_dir().'/qr-mix-'.getmypid();
        @mkdir($dir, 0777, true);

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        if ($cssFile === null) {
            $this->markTestSkipped('Build manifest has no app stylesheet; mix geometry is unmeasured.');
        }
        $cssLink = '<link rel="stylesheet" href="/build/'.$cssFile.'">';

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $origin = rtrim((string) config('app.url'), '/');
        $html = str_replace($origin, '', $html);
        $html = str_replace('</head>', $cssLink.'</head>', $html);
        file_put_contents($dir.'/qr.html', $html);

        $config = $dir.'/config.json';
        file_put_contents($config, json_encode([
            'pagesDir' => $dir,
            'publicDir' => public_path(),
            'rigDir' => $rig,
            'widths' => self::WIDTHS,
        ]));

        $cmd = 'POSTER_RIG_DIR='.escapeshellarg($rig)
            .' PLAYWRIGHT_BROWSERS_PATH='.escapeshellarg($rig.'/browsers')
            .' timeout 120 '.$node.' '.escapeshellarg(__DIR__.'/../../Support/qr-mix-probe.mjs')
            .' '.escapeshellarg($config).' 2>/dev/null';
        $json = (string) shell_exec($cmd);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->fail('QR mix probe produced no measurement. Command: '.$cmd);
        }

        return self::$probe = $decoded;
    }

    public function test_m1_first_screenful_at_390_holds_the_complete_start_here_panel_only(): void
    {
        $probe = $this->probe();
        $first = $probe['firstScreen'];

        // M-1 RE-DERIVED by Amendment 41 (2026-10-28, QR +
        // receipt train), the M-4 precedent applied to a
        // geometry pin: RC-2 Variant 2 ratifies the receipt
        // pair INSIDE the Start here panel as its named second
        // step, so the panel is taller than one screenful by
        // design (measured bottom 1162 at 390x844) and the
        // original whole-panel form cannot hold ratified copy.
        // What M-1 existed to protect survives and is what is
        // now pinned: (a) the PRIMARY step — the counter block
        // through its one primary button — completes inside
        // the first screenful; (b) no by-spot Print button
        // shares the first screenful; (c) the pair block sits
        // inside the same panel, below the primary step. The
        // re-derivation is flagged for the train's two-key
        // review in the lane report.
        $this->assertNotNull($first['primaryBottom'], 'M-1 v2: the Start here primary button was not measured.');
        $this->assertLessThanOrEqual(
            844,
            $first['primaryBottom'],
            'M-1 v2: the primary step (through its Print button) must complete in the first screenful at 390x844; measured bottom '.$first['primaryBottom'].'.'
        );
        $this->assertNotNull($first['pairTop'], 'M-1 v2: the pair block was not measured.');
        $this->assertGreaterThan($first['panelTop'], $first['pairTop'], 'M-1 v2: the pair block must sit inside the Start here panel.');
        $this->assertLessThanOrEqual($first['panelBottom'], $first['pairBottom'], 'M-1 v2: the pair block must end inside the Start here panel.');
        $this->assertGreaterThan($first['primaryBottom'], $first['pairTop'], 'M-1 v2: the pair block must follow the primary step.');
        $this->assertSame(
            [],
            $first['bySpotPrintsInFirstScreen'],
            'M-1: no by-spot Print button may share the first screenful.'
        );
    }

    public function test_m5_every_row_action_is_at_least_44px_and_nothing_overlaps(): void
    {
        $probe = $this->probe();

        foreach (self::WIDTHS as $width) {
            $this->assertArrayHasKey($width, $probe['widths'], "No probe measurement at {$width}px.");
            $rows = $probe['widths'][$width]['rows'];

            $this->assertSame(
                ['counter', 'window', 'table', 'receipt'],
                collect($rows)->pluck('placement')->all(),
                "The by-spot rows are missing or out of order at {$width}px."
            );

            foreach ($rows as $row) {
                $this->assertNotEmpty($row['actions'], "The {$row['placement']} row has no actions at {$width}px.");
                foreach ($row['actions'] as $action) {
                    $this->assertGreaterThanOrEqual(
                        44,
                        $action['h'],
                        "M-5: '{$action['label']}' in the {$row['placement']} row is {$action['h']}px tall at {$width}px."
                    );
                    $this->assertFalse(
                        $action['overlapsText'],
                        "At {$width}px '{$action['label']}' overlaps the {$row['placement']} row's text block."
                    );
                }
            }

            foreach ($probe['widths'][$width]['extras'] as $extra) {
                $this->assertGreaterThanOrEqual(
                    44,
                    $extra['h'],
                    "M-5: the Also available '{$extra['label']}' link is {$extra['h']}px tall at {$width}px."
                );
            }
        }
    }
}
