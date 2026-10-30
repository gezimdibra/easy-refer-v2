<?php

namespace Tests\Feature\Owner;

use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * T2-F3: "Download QR code" must download. The POST used to
 * redirect to /auth/qr/image, which serves the SVG inline with no
 * Content-Disposition, so the browser just displayed a bare SVG
 * page. The download action now serves the file as an attachment;
 * the image route stays inline for the page's own <img> tags.
 * The poster links are pinned too: they open the print page, so
 * they say "Print poster" (the label the page's own quick action
 * already used), never "Download".
 */
class QrDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_serves_the_qr_as_an_attachment(): void
    {
        $business = World::business();
        World::qr($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('owner.qr.download'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('easy-refer-qr-code.svg', $disposition);
        $this->assertStringContainsString('<svg', $response->getContent());

        // The download still records, as before.
        $this->assertDatabaseHas('referral_events', ['business_id' => $business->id, 'event_type' => 'poster_downloaded']);
    }

    public function test_qr_page_headlines_the_mini_poster_and_demotes_the_bare_code(): void
    {
        $business = World::business();
        World::qr($business);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('owner.qr'))->assertOk();

        // F-06 (coordinator ruling, Train 1 fix round): the
        // mini-poster is a print artifact this release, so its
        // action says Print; "Download" promised a file that
        // never came (the T2-F5 mismatch class). P3 MIX-4: the
        // mini-poster now sits in the Also available rows, its
        // action a plain "Print" beside its name; the old
        // "Print mini-poster" button label is retired (pinned
        // absent in QrPosterMixTest).
        $page->assertSee('Mini-poster');
        $page->assertDontSee('Download mini-poster');
        $page->assertDontSee('Print mini-poster');
        $page->assertSee('/qr/poster/mini?print=1', false);
        $page->assertSee('Download code only (for your own designs)');
        $page->assertDontSee('Download QR code');
    }

    public function test_mini_poster_is_the_headline_artifact_and_records_once(): void
    {
        $business = World::business();
        World::qr($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'mini', 'print' => 1]));
        $response->assertOk();

        $text = html_entity_decode(strip_tags($response->getContent()));
        $text = (string) preg_replace('/\s+/', ' ', $text);

        $this->assertStringContainsString('Business 1', $text);
        // Amendment 58 [REF-POSTER-04]: the mini-poster headline
        // is the poster composer output (the give/get sentence);
        // the friend reward text rides inside it verbatim and the
        // old "Get {reward-alone}" form stays absent.
        $this->assertStringContainsString($business->friendRewardHeadline(), $text);
        $this->assertStringNotContainsString('Get '.$business->friendRewardHeadline(), $text);
        $this->assertStringContainsString($business->posterHeadline(), $text);
        $this->assertStringContainsString('YOUR REWARD', $text);
        $this->assertStringContainsString('Scan to share', $text);
        $this->assertStringContainsString('Rewards for sharing.', $text);
        $this->assertStringNotContainsString('1. Scan', $text);
        $this->assertStringNotContainsString('No app to install', $text);
        $response->assertSee('poster-halo', false);
        $response->assertSee('size: A6', false);

        $this->assertSame(1, ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'poster_downloaded')
            ->count());
    }

    public function test_image_route_stays_inline_for_display(): void
    {
        $business = World::business();
        World::qr($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.image'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertNull($response->headers->get('Content-Disposition'));
    }

    public function test_poster_buttons_say_print_poster_and_open_the_print_page(): void
    {
        $business = World::business();
        World::qr($business);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('owner.qr'))->assertOk();

        // P3 MIX grammar: every print action names its artifact.
        // The counter print URL appears twice by design (the
        // Start here action and the counter row, M-3 href
        // identity); the mini rides the Also available row with
        // a plain "Print". Amendment 31 [ON-QR-1]: the screen
        // variant is retired, so its print URL is absent (its
        // 404 is pinned in QrSpotRenameTest).
        $page->assertSee('Print this poster');
        $page->assertSee('Print the counter poster');
        $page->assertSee('Print the window poster');
        $page->assertSee('Print the table tent');
        $page->assertSee('Print the receipt card');
        foreach (['counter', 'window', 'tent', 'receipt', 'mini'] as $variant) {
            $page->assertSee('/qr/poster/'.$variant.'?print=1', false);
        }
        $page->assertDontSee('/qr/poster/screen?print=1', false);
        $this->assertSame(2, substr_count($page->getContent(), '/qr/poster/counter?print=1'));

        // No poster link still promises a download that never comes.
        $page->assertDontSee('> Download</a>', false);
    }

    public function test_poster_list_rows_stack_on_mobile_and_stay_rows_on_desktop(): void
    {
        $business = World::business();
        World::qr($business);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('owner.qr'))->assertOk();
        $html = $page->getContent();

        // P3 MIX-2: the old five-row poster list (and its F-05
        // row structure) is retired; the by-spot panel carries
        // four placement rows, each with its named print link.
        // F-05's phone-width concern (labels squeezed, actions
        // cramped) is carried forward by QrPosterMixGeometryTest:
        // every action at least 44px tall and zero overlaps at
        // 390 and 375 in the real rig.
        $this->assertSame(4, substr_count($html, 'data-placement='));
        foreach (['counter', 'window', 'table', 'receipt'] as $placement) {
            $this->assertStringContainsString('data-placement="'.$placement.'"', $html);
        }
        $page->assertSee('Print by spot');
    }

}
