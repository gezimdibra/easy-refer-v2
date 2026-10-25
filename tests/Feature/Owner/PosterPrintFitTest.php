<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Standing print checks (Train 1 fix round; the review's named
 * checks, owner-correction doctrine). Train 1 shipped a print
 * kit whose sheets were taller than the paper their own @page
 * rules name (counter 154% of A5, mini 185% of A6, window past
 * A4, the tent card clipping its own scan instruction), and no
 * test anywhere measured rendered geometry. These two tests are
 * that measurement, permanently:
 *
 *  1. Pagination: every poster variant renders to exactly its
 *     designed sheet count at its named @page size under print
 *     emulation — each sheet's box, plus its print margins,
 *     fits inside its page box.
 *  2. Geometry: every element inside a sheet sits inside the
 *     sheet's box on the print-emulated render (nothing clips,
 *     nothing spills).
 *
 * The measurement runs in the WebKit rig (tests/Support/
 * poster-print-probe.mjs) against pages rendered by the test
 * client. On a machine without the rig the tests skip rather
 * than fail; on this machine they are part of the suite.
 *
 * Page boxes are CSS px at 96dpi for each variant's @page:
 * A5 portrait 559x794, A4 portrait 794x1123, A6 portrait
 * 397x559, A5 landscape 794x559, receipt card 85x55mm
 * (321x208), receipt strip 80x120mm (302x454). The screen
 * variant names no @page; it is held to A4, the browser print
 * default it will actually meet.
 */
class PosterPrintFitTest extends TestCase
{
    use RefreshDatabase;

    /** variant => [pageW, pageH] per designed sheet */
    private const PAGES = [
        'screen' => [[794, 1123]],
        'counter' => [[559, 794]],
        'window' => [[794, 1123]],
        'mini' => [[397, 559]],
        'tent' => [[794, 559], [794, 559]],
        'receipt' => [[321.3, 207.9], [302.4, 453.5]],
    ];

    private static ?array $probe = null;

    private function probe(): array
    {
        if (self::$probe !== null) {
            return self::$probe;
        }

        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; print geometry is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; print geometry is unmeasured on this machine.');
        }

        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $dir = sys_get_temp_dir().'/poster-print-'.getmypid();
        @mkdir($dir, 0777, true);

        // The suite disables Vite (TestCase::withoutVite), so
        // the rendered page carries no stylesheet link; the
        // probe needs the real built CSS or every measurement
        // is of an unstyled page. Inject the one stylesheet the
        // live poster page loads, named by the build manifest.
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        if ($cssFile === null) {
            $this->markTestSkipped('Build manifest has no app stylesheet; print geometry is unmeasured.');
        }
        $cssLink = '<link rel="stylesheet" href="/build/'.$cssFile.'">';

        $origin = rtrim((string) config('app.url'), '/');
        foreach (array_keys(self::PAGES) as $variant) {
            $html = $this->actingAs($owner)->get(route('owner.qr.poster', $variant))->assertOk()->getContent();
            // Root every absolute app URL on the probe's own
            // origin so its static server can answer for the
            // built assets, the fonts, and the QR image route.
            $html = str_replace($origin, '', $html);
            $html = str_replace('</head>', $cssLink.'</head>', $html);
            file_put_contents($dir.'/'.$variant.'.html', $html);
        }
        $svg = $this->actingAs($owner)->get(route('owner.qr.image'))->assertOk()->getContent();
        file_put_contents($dir.'/qr.svg', $svg);

        $variants = [];
        foreach (self::PAGES as $variant => $sheets) {
            $variants[$variant] = ['pageW' => $sheets[0][0], 'pageH' => $sheets[0][1]];
        }
        $config = $dir.'/config.json';
        file_put_contents($config, json_encode([
            'pagesDir' => $dir,
            'publicDir' => public_path(),
            'rigDir' => $rig,
            'variants' => $variants,
        ]));

        $cmd = 'POSTER_RIG_DIR='.escapeshellarg($rig)
            .' PLAYWRIGHT_BROWSERS_PATH='.escapeshellarg($rig.'/browsers')
            .' timeout 180 '.$node.' '.escapeshellarg(__DIR__.'/../../Support/poster-print-probe.mjs')
            .' '.escapeshellarg($config).' 2>/dev/null';
        $json = (string) shell_exec($cmd);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->fail('Poster print probe produced no measurement. Command: '.$cmd);
        }

        return self::$probe = $decoded;
    }

    public function test_every_poster_variant_paginates_to_its_designed_sheets(): void
    {
        $probe = $this->probe();

        foreach (self::PAGES as $variant => $pages) {
            $this->assertArrayHasKey($variant, $probe, "No probe measurement for [{$variant}].");
            $sheets = $probe[$variant]['sheets'];
            $this->assertCount(count($pages), $sheets, "[{$variant}] rendered the wrong number of sheets.");

            foreach ($pages as $i => [$pageW, $pageH]) {
                $sheet = $sheets[$i];
                $margin = $probe[$variant]['print']['margins'][$i] ?? ['mt' => 0, 'mb' => 0];
                $consumed = $sheet['h'] + $margin['mt'] + $margin['mb'];

                $this->assertLessThanOrEqual(
                    $pageW + 1,
                    $sheet['w'],
                    "[{$variant}] sheet {$i} is {$sheet['w']}px wide against a {$pageW}px page."
                );
                $this->assertLessThanOrEqual(
                    $pageH + 1,
                    $consumed,
                    "[{$variant}] sheet {$i} consumes {$consumed}px of a {$pageH}px page (box {$sheet['h']} + print margins {$margin['mt']}+{$margin['mb']}); it would fragment across pages."
                );
                // Print margins are zero by construction (the
                // @media print block resets them), so a sheet
                // never starts pushed down its page.
                $this->assertEqualsWithDelta(0.0, (float) $margin['mt'], 0.6, "[{$variant}] sheet {$i} keeps a top print margin.");
                $this->assertEqualsWithDelta(0.0, (float) $margin['mb'], 0.6, "[{$variant}] sheet {$i} keeps a bottom print margin.");
            }

            $this->assertSame('none', $probe[$variant]['print']['actionsDisplay'], "[{$variant}] still shows the actions row in print.");
        }
    }

    public function test_every_element_sits_inside_its_sheet_box(): void
    {
        $probe = $this->probe();

        foreach (self::PAGES as $variant => $pages) {
            foreach ($probe[$variant]['sheets'] as $i => $sheet) {
                $this->assertSame(
                    [],
                    $sheet['violations'],
                    "[{$variant}] sheet {$i} has elements outside its box: ".json_encode(array_slice($sheet['violations'], 0, 3))
                );
            }
        }
    }
}
