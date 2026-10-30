<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
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
 * (321x208), receipt strip 80x120mm (302x454). (Amendment 31
 * [ON-QR-1] retired the screen variant, which this map used
 * to hold to A4; it no longer resolves.)
 *
 * RE-DERIVATION (Amendment 43, card-fit hotfix): the generic
 * probe below renders the default World fixture (short name,
 * short headline, localhost-class fallback slug), which
 * UNDERSTATES the receipt card — the r45 re-check (defect
 * D-1) measured the live Harbor Street Barbers card at 227px
 * of content in a 206px box while this suite stayed green.
 * The generic pins keep running unchanged for the flowing
 * sheets, but the receipt card's fit claim no longer rests on
 * them: test_receipt_card_fits_worst_case_content() renders
 * biz-7-class worst-case content (long name, long headline,
 * production-domain fallback with a long slug) and asserts
 * the measured fit on the rig. Standing rule (Amendment 43):
 * any future fit pin must carry worst-case content, not
 * nominal.
 */
class PosterPrintFitTest extends TestCase
{
    use RefreshDatabase;

    /** variant => [pageW, pageH] per designed sheet */
    private const PAGES = [
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

    // ---------- Amendment 43: the card-fit named check ----------

    private static ?array $worstProbe = null;

    /**
     * The worst-case render: biz 7's live content class, not
     * the nominal fixture. Long business name ("Harbor Street
     * Barbers"), friend text "$10 off your first cut" and
     * referrer text "$10 off your next cut", split on
     * (Amendment 58: the card's headline is now the give/get
     * sentence itself — "Give a friend $10 off your first
     * cut. Get $10 off your next cut when they visit." —
     * 4 lines at card width, the longest stack the card can
     * compose), and the app URL set
     * to the production mount so the typed fallback renders
     * "easy-refer.com/new/join/harbor-street-barbers" (3 lines
     * at card width), exactly the state the r45 re-check
     * measured clipping live (227px content / 206px box).
     *
     * Condition-1 discharge (two-key review, Card-Fit Hotfix):
     * a fourth render, receiptstress, carries the same
     * both-texts stack with the slug extended by exactly 13
     * characters ("harbor-street-barbers-and-grooming", the
     * "-and-grooming" class) — the fix's headroom proof. Its
     * fallback must still print complete, inside the box,
     * wrapping to at most 2 full-width lines.
     *
     * @return array{probe: array, html: string, multiHtml: string, stressHtml: string, fallbacks: array}
     */
    private function worstCaseProbe(): array
    {
        if (self::$worstProbe !== null) {
            return self::$worstProbe;
        }

        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; card fit is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; card fit is unmeasured on this machine.');
        }

        // The production mount, so the typed fallback renders
        // with the production domain. route() reads the URL
        // generator's forced root, not the config value alone.
        config(['app.url' => 'https://easy-refer.com/new']);
        URL::forceRootUrl('https://easy-refer.com/new');

        $business = World::business([
            'name' => 'Harbor Street Barbers',
            'friend_reward_text' => '$10 off your first cut',
            'referrer_reward_text' => '$10 off your next cut',
        ]);
        $owner = World::owner($business);
        World::qr($business);

        $dir = sys_get_temp_dir().'/poster-cardfit-'.getmypid();
        @mkdir($dir, 0777, true);

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        if ($cssFile === null) {
            $this->markTestSkipped('Build manifest has no app stylesheet; card fit is unmeasured.');
        }
        $cssLink = '<link rel="stylesheet" href="/build/'.$cssFile.'">';

        $origin = rtrim((string) config('app.url'), '/');
        $html = $this->actingAs($owner)->get(route('owner.qr.poster', 'receipt'))->assertOk()->getContent();
        $html = str_replace($origin, '', $html);
        $html = str_replace('</head>', $cssLink.'</head>', $html);
        file_put_contents($dir.'/receipt.html', $html);

        $multiHtml = $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'receipt', 'multi' => 1]))->assertOk()->getContent();
        $multiHtml = str_replace($origin, '', $multiHtml);
        $multiHtml = str_replace('</head>', $cssLink.'</head>', $multiHtml);
        file_put_contents($dir.'/receiptmulti.html', $multiHtml);

        $svg = $this->actingAs($owner)->get(route('owner.qr.image'))->assertOk()->getContent();
        file_put_contents($dir.'/qr.svg', $svg);

        // The card's sibling states, same long name and
        // production mount: referrer-text-only (under OR-1
        // canonical storage this is the UNSET headline state:
        // no friend text, so the card composes the generic
        // share headline) and neither-text. A fit claim
        // for one state that clips in another is the D-1 class
        // again, so all three are pinned. Slugs uniquify per
        // business, so each variant's expected fallback is
        // recorded from its own business.
        $fallbacks = ['receipt' => 'easy-refer.com/new/join/'.$business->ensureJoinSlug()];
        $stateVariants = [];
        foreach ([
            'receiptrefonly' => ['friend_reward_text' => null, 'referrer_reward_text' => '$10 off your next cut'],
            'receiptnotexts' => ['friend_reward_text' => null, 'referrer_reward_text' => null],
            // OR-1 §8.10 (Amendment 49): the UNIFIED configuration
            // at the same worst case — one reward in the unified
            // headline ("Share with a friend, you both get $10
            // off your first cut when they visit."), the longest
            // single-text headline the card can compose.
            'receiptunified' => ['friend_reward_text' => '$10 off your first cut', 'referrer_reward_text' => null, 'reward_split' => false],
        ] as $variantKey => $texts) {
            $stateBusiness = World::business(array_merge(['name' => 'Harbor Street Barbers'], $texts));
            $stateOwner = World::owner($stateBusiness);
            World::qr($stateBusiness);
            $stateHtml = $this->actingAs($stateOwner)->get(route('owner.qr.poster', 'receipt'))->assertOk()->getContent();
            $stateHtml = str_replace($origin, '', $stateHtml);
            $stateHtml = str_replace('</head>', $cssLink.'</head>', $stateHtml);
            file_put_contents($dir.'/'.$variantKey.'.html', $stateHtml);
            if ($variantKey === 'receiptunified') {
                $unifiedHtml = $stateHtml;
            }
            $fallbacks[$variantKey] = 'easy-refer.com/new/join/'.$stateBusiness->ensureJoinSlug();
            $stateVariants[$variantKey] = ['pageW' => 321.3, 'pageH' => 207.9];
        }

        // Stress variant (Condition 1): the identical
        // both-texts stack and business name, with ONLY the
        // slug extended — biz 7's slug plus exactly 13
        // characters ("-and-grooming"). The name is held
        // constant so the render isolates the fallback's
        // length: any change in the measured stack is the
        // longer address alone. The slug is set explicitly
        // (it is fillable and ensureJoinSlug() preserves a
        // stored slug) so the +13 arithmetic cannot drift
        // with slug generation.
        $stressSlug = 'harbor-street-barbers-and-grooming';
        $stressBusiness = World::business([
            'name' => 'Harbor Street Barbers',
            'friend_reward_text' => '$10 off your first cut',
            'referrer_reward_text' => '$10 off your next cut',
            'slug' => $stressSlug,
        ]);
        $stressOwner = World::owner($stressBusiness);
        World::qr($stressBusiness);
        $stressHtml = $this->actingAs($stressOwner)->get(route('owner.qr.poster', 'receipt'))->assertOk()->getContent();
        $stressHtml = str_replace($origin, '', $stressHtml);
        $stressHtml = str_replace('</head>', $cssLink.'</head>', $stressHtml);
        file_put_contents($dir.'/receiptstress.html', $stressHtml);
        $fallbacks['receiptstress'] = 'easy-refer.com/new/join/'.$stressBusiness->ensureJoinSlug();
        $stateVariants['receiptstress'] = ['pageW' => 321.3, 'pageH' => 207.9];

        $config = $dir.'/config.json';
        file_put_contents($config, json_encode([
            'pagesDir' => $dir,
            'publicDir' => public_path(),
            'rigDir' => $rig,
            'variants' => array_merge([
                'receipt' => ['pageW' => 321.3, 'pageH' => 207.9],
                'receiptmulti' => ['pageW' => 794, 'pageH' => 1123],
            ], $stateVariants),
        ]));

        $cmd = 'POSTER_RIG_DIR='.escapeshellarg($rig)
            .' PLAYWRIGHT_BROWSERS_PATH='.escapeshellarg($rig.'/browsers')
            .' timeout 180 '.$node.' '.escapeshellarg(__DIR__.'/../../Support/poster-print-probe.mjs')
            .' '.escapeshellarg($config).' 2>/dev/null';
        $json = (string) shell_exec($cmd);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->fail('Card-fit probe produced no measurement. Command: '.$cmd);
        }

        // Evidence dump for the lane's before/after captures:
        // only when the lane asks for it by env var.
        if ($dump = getenv('CARD_FIT_DUMP_DIR')) {
            @mkdir($dump, 0777, true);
            file_put_contents($dump.'/worst-probe.json', json_encode($decoded, JSON_PRETTY_PRINT));
            file_put_contents($dump.'/receipt-worst.html', $html);
            file_put_contents($dump.'/receiptmulti-worst.html', $multiHtml);
            file_put_contents($dump.'/receiptstress-worst.html', $stressHtml);
            copy($dir.'/qr.svg', $dump.'/qr.svg');
        }

        return self::$worstProbe = ['probe' => $decoded, 'html' => $html, 'multiHtml' => $multiHtml, 'stressHtml' => $stressHtml, 'fallbacks' => $fallbacks, 'unifiedHtml' => $unifiedHtml ?? ''];
    }

    public function test_receipt_card_fits_worst_case_content(): void
    {
        $worst = $this->worstCaseProbe();
        $probe = $worst['probe'];

        // Fixture honesty guards: the render under measurement
        // really carries the worst-case content. If a future
        // change shortens any of these, the guards fail first
        // and the fit assertion below is never trusted again
        // on a shrunken fixture.
        $this->assertStringContainsString('Harbor Street Barbers', $worst['html']);
        // Amendment 58: the headline guards read rendered text
        // (the F5 amount spans interrupt the sentence in raw
        // markup); the fit assertions below are the rig's.
        $worstText = (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($worst['html'])));
        $this->assertStringContainsString(
            'Give a friend $10 off your first cut. Get $10 off your next cut when they visit.',
            $worstText,
            'Worst-case fixture: the split give/get headline text must render.'
        );
        $this->assertStringContainsString(
            'easy-refer.com/new/join/harbor-street-barbers',
            $worst['html'],
            'Worst-case fixture: the production-domain fallback must render.'
        );

        // The single card (85x55mm sheet): content height must
        // not exceed the box, no element may cross the box,
        // and the fallback paragraph must end inside the box
        // with its full text un-truncated.
        $card = $probe['receipt']['sheets'][0];
        $this->assertLessThanOrEqual(
            $card['clientH'],
            $card['scrollH'],
            "Worst-case card content is {$card['scrollH']}px in a {$card['clientH']}px box (r45 D-1 measured 227 in 206 live)."
        );
        $this->assertSame(
            [],
            $card['violations'],
            'Worst-case card has elements outside its box: '.json_encode(array_slice($card['violations'], 0, 3))
        );
        $this->assertNotNull($card['fallback'], 'Worst-case card carries no fallback paragraph.');
        $this->assertSame(
            "Can't scan? Go to easy-refer.com/new/join/harbor-street-barbers",
            $card['fallback']['text'],
            'The fallback URL must print complete and unaltered.'
        );
        $this->assertLessThanOrEqual(
            $card['h'],
            $card['fallback']['bottomRel'],
            "Worst-case fallback ends at {$card['fallback']['bottomRel']}px in a {$card['h']}px card; its last line clips."
        );

        // Sibling states, same named check: the referrer-only
        // and neither-text cards must fit in the same box with
        // their (uniquified) production fallbacks complete.
        // OR-1 fixture honesty: the unified state's render
        // really carries the unified give-get line.
        $unifiedText = (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($worst['unifiedHtml'])));
        $this->assertStringContainsString(
            'Share with a friend, you both get $10 off your first cut when they visit.',
            $unifiedText,
            'Unified worst-case fixture: the unified headline text must render.'
        );

        foreach (['receiptrefonly', 'receiptnotexts', 'receiptunified'] as $variantKey) {
            $stateCard = $probe[$variantKey]['sheets'][0];
            $expected = "Can't scan? Go to ".$worst['fallbacks'][$variantKey];
            $this->assertLessThanOrEqual(
                $stateCard['clientH'],
                $stateCard['scrollH'],
                "[{$variantKey}] card content is {$stateCard['scrollH']}px in a {$stateCard['clientH']}px box."
            );
            $this->assertSame(
                [],
                $stateCard['violations'],
                "[{$variantKey}] card has elements outside its box: ".json_encode(array_slice($stateCard['violations'], 0, 3))
            );
            $this->assertNotNull($stateCard['fallback'], "[{$variantKey}] card carries no fallback paragraph.");
            $this->assertSame($expected, $stateCard['fallback']['text'], "[{$variantKey}] fallback must print complete and unaltered.");
            $this->assertLessThanOrEqual(
                $stateCard['h'],
                $stateCard['fallback']['bottomRel'],
                "[{$variantKey}] fallback ends at {$stateCard['fallback']['bottomRel']}px in a {$stateCard['h']}px card; its last line clips."
            );
        }

        // Stress state (Condition 1): biz 7's slug + 13 chars
        // ("-and-grooming"). The fallback must print complete
        // and inside the box, wrapping to at most 2 full-width
        // lines (the fallback's line-height is 12px, so two
        // lines measure 24px; the unstressed card's fallback
        // is one 12px line).
        $this->assertSame(
            13,
            strlen('harbor-street-barbers-and-grooming') - strlen('harbor-street-barbers'),
            'Stress fixture: the stress slug must exceed biz 7\'s slug by exactly 13 characters.'
        );
        $this->assertStringContainsString(
            'easy-refer.com/new/join/harbor-street-barbers-and-grooming',
            $worst['stressHtml'],
            'Stress fixture: the +13-char production fallback must render.'
        );
        $stressCard = $probe['receiptstress']['sheets'][0];
        $stressExpected = "Can't scan? Go to ".$worst['fallbacks']['receiptstress'];
        $this->assertSame(
            "Can't scan? Go to easy-refer.com/new/join/harbor-street-barbers-and-grooming",
            $stressExpected,
            'Stress fixture: the recorded stress fallback must be the +13-char address.'
        );
        $this->assertLessThanOrEqual(
            $stressCard['clientH'],
            $stressCard['scrollH'],
            "[receiptstress] card content is {$stressCard['scrollH']}px in a {$stressCard['clientH']}px box."
        );
        $this->assertSame(
            [],
            $stressCard['violations'],
            '[receiptstress] card has elements outside its box: '.json_encode(array_slice($stressCard['violations'], 0, 3))
        );
        $this->assertNotNull($stressCard['fallback'], '[receiptstress] card carries no fallback paragraph.');
        $this->assertSame($stressExpected, $stressCard['fallback']['text'], '[receiptstress] fallback must print complete and unaltered.');
        $this->assertLessThanOrEqual(
            $stressCard['h'],
            $stressCard['fallback']['bottomRel'],
            "[receiptstress] fallback ends at {$stressCard['fallback']['bottomRel']}px in a {$stressCard['h']}px card; its last line clips."
        );
        $stressFallbackHeight = $stressCard['fallback']['bottomRel'] - $stressCard['fallback']['topRel'];
        $this->assertLessThanOrEqual(
            24.5,
            $stressFallbackHeight,
            "[receiptstress] fallback wraps to more than 2 full-width lines ({$stressFallbackHeight}px at a 12px line-height)."
        );

        // The strip on the same page keeps fitting (it shares
        // the page, not the card partial; a regression here
        // would mean the fix leaked out of the card).
        $strip = $probe['receipt']['sheets'][1];
        $this->assertLessThanOrEqual(
            $strip['clientH'],
            $strip['scrollH'],
            "Worst-case strip content is {$strip['scrollH']}px in a {$strip['clientH']}px box."
        );

        // The 8-up sheet: the same shared card composition in
        // fixed cut guides — every one of the 8 must fit.
        $guides = $probe['receiptmulti']['guides'];
        $this->assertCount(8, $guides, 'The multi-up sheet must carry 8 cards.');
        foreach ($guides as $i => $guide) {
            $this->assertLessThanOrEqual(
                $guide['clientH'],
                $guide['scrollH'],
                "Multi-up card {$i} content is {$guide['scrollH']}px in a {$guide['clientH']}px cut guide."
            );
            $this->assertSame(
                [],
                $guide['violations'],
                "Multi-up card {$i} has elements outside its cut guide: ".json_encode(array_slice($guide['violations'], 0, 3))
            );
            $this->assertNotNull($guide['fallback'], "Multi-up card {$i} carries no fallback paragraph.");
            $this->assertSame(
                "Can't scan? Go to easy-refer.com/new/join/harbor-street-barbers",
                $guide['fallback']['text'],
                "Multi-up card {$i}: the fallback URL must print complete and unaltered."
            );
            $this->assertLessThanOrEqual(
                $guide['h'],
                $guide['fallback']['bottomRel'],
                "Multi-up card {$i} fallback ends at {$guide['fallback']['bottomRel']}px in a {$guide['h']}px cut guide; its last line clips."
            );
        }
    }

    // ---------- Amendment 58: worst-case headlines, all variants ----------

    private static ?array $headlineProbe = null;

    /**
     * Amendment 58 [REF-POSTER-04]: the headline is a full
     * give/get sentence now, so the Amendment 43 discipline
     * (fit pins carry worst-case content) extends from the
     * receipt card to EVERY variant, in BOTH configurations.
     * SPLIT worst case: the biz-7 texts ("$10 off your first
     * cut" / "$10 off your next cut", headline 80 chars).
     * UNIFIED worst case: one long text ("20 percent off
     * your first visit", headline 82 chars). Every sheet of
     * every variant must paginate to its designed page and
     * hold every element inside its box, in both.
     *
     * @return array{probe: array, headlines: array<string, string>}
     */
    private function headlineProbe(): array
    {
        if (self::$headlineProbe !== null) {
            return self::$headlineProbe;
        }

        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; headline fit is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; headline fit is unmeasured on this machine.');
        }

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        if ($cssFile === null) {
            $this->markTestSkipped('Build manifest has no app stylesheet; headline fit is unmeasured.');
        }
        $cssLink = '<link rel="stylesheet" href="/build/'.$cssFile.'">';

        $dir = sys_get_temp_dir().'/poster-headline-'.getmypid();
        @mkdir($dir, 0777, true);

        $configs = [
            'split' => ['friend_reward_text' => '$10 off your first cut', 'referrer_reward_text' => '$10 off your next cut', 'reward_split' => true],
            'unified' => ['friend_reward_text' => '20 percent off your first visit', 'referrer_reward_text' => null, 'reward_split' => false],
        ];

        $variants = [];
        $headlines = [];
        $origin = rtrim((string) config('app.url'), '/');
        foreach ($configs as $label => $overrides) {
            $business = World::business(array_merge(['name' => 'Harbor Street Barbers'], $overrides));
            $owner = World::owner($business);
            World::qr($business);
            $headlines[$label] = $business->posterHeadline();

            foreach (self::PAGES as $variant => $pages) {
                $key = $label.$variant;
                $html = $this->actingAs($owner)->get(route('owner.qr.poster', $variant))->assertOk()->getContent();
                $html = str_replace($origin, '', $html);
                $html = str_replace('</head>', $cssLink.'</head>', $html);
                file_put_contents($dir.'/'.$key.'.html', $html);
                $variants[$key] = ['pageW' => $pages[0][0], 'pageH' => $pages[0][1]];

                // Fixture honesty: the render under measurement
                // carries the configuration's headline verbatim.
                $renderedText = (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
                $this->assertStringContainsString($headlines[$label], $renderedText, "[{$key}] fixture does not carry its headline.");
            }

            if ($label === 'split') {
                $svg = $this->actingAs($owner)->get(route('owner.qr.image'))->assertOk()->getContent();
                file_put_contents($dir.'/qr.svg', $svg);
            }
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
            .' timeout 240 '.$node.' '.escapeshellarg(__DIR__.'/../../Support/poster-print-probe.mjs')
            .' '.escapeshellarg($config).' 2>/dev/null';
        $json = (string) shell_exec($cmd);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->fail('Headline-fit probe produced no measurement. Command: '.$cmd);
        }

        if ($dump = getenv('HEADLINE_FIT_DUMP_DIR')) {
            @mkdir($dump, 0777, true);
            file_put_contents($dump.'/headline-probe.json', json_encode($decoded, JSON_PRETTY_PRINT));
        }

        return self::$headlineProbe = ['probe' => $decoded, 'headlines' => $headlines];
    }

    public function test_every_variant_fits_worst_case_headlines_in_both_configurations(): void
    {
        $probe = $this->headlineProbe()['probe'];

        foreach (['split', 'unified'] as $label) {
            foreach (self::PAGES as $variant => $pages) {
                $key = $label.$variant;
                $this->assertArrayHasKey($key, $probe, "No probe measurement for [{$key}].");
                $sheets = $probe[$key]['sheets'];
                $this->assertCount(count($pages), $sheets, "[{$key}] rendered the wrong number of sheets.");

                foreach ($pages as $i => [$pageW, $pageH]) {
                    $sheet = $sheets[$i];
                    $margin = $probe[$key]['print']['margins'][$i] ?? ['mt' => 0, 'mb' => 0];
                    $consumed = $sheet['h'] + $margin['mt'] + $margin['mb'];

                    $this->assertLessThanOrEqual(
                        $pageW + 1,
                        $sheet['w'],
                        "[{$key}] sheet {$i} is {$sheet['w']}px wide against a {$pageW}px page."
                    );
                    $this->assertLessThanOrEqual(
                        $pageH + 1,
                        $consumed,
                        "[{$key}] sheet {$i} consumes {$consumed}px of a {$pageH}px page; the worst-case headline fragments it."
                    );
                    $this->assertSame(
                        [],
                        $sheet['violations'],
                        "[{$key}] sheet {$i} has elements outside its box: ".json_encode(array_slice($sheet['violations'], 0, 3))
                    );
                }
            }
        }
    }
}
