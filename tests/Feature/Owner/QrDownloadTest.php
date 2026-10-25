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
        // action says Print, sibling-consistent with the other
        // poster actions; "Download" promised a file that
        // never came (the T2-F5 mismatch class). Shareable
        // raster images are decided once in Train 3 (QRK-08).
        $page->assertSee('Print mini-poster');
        $page->assertDontSee('Download mini-poster');
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
        $this->assertStringContainsString('Get $10 off your first visit', $text);
        $this->assertStringContainsString('Give $10 off. Get $10 off when your friend visits.', $text);
        $this->assertStringContainsString('YOUR REWARD', $text);
        $this->assertStringContainsString('Scan to join', $text);
        $this->assertStringContainsString('Free to join. Rewards for sharing.', $text);
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

        // One print action per variant (screen, counter, window,
        // tent, receipt), each opening its poster page in print mode.
        $this->assertSame(5, substr_count($page->getContent(), 'Print poster'));
        foreach (['screen', 'counter', 'window', 'tent', 'receipt'] as $variant) {
            $page->assertSee('/qr/poster/'.$variant.'?print=1', false);
        }
        $page->assertSee('Table tent, two faces');
        $page->assertSee('Receipt / thank-you card');

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

        // F-05: at phone width the desktop row (thumb + flex-1
        // label + button on one line) squeezed the labels into
        // word-per-line stacks. The list now stacks per row on
        // mobile (thumb + label on one line, the print action
        // full-width below) and returns to the desktop row at
        // sm and up. The structure is the check: five rows, each
        // carrying the stacked-column mobile layout with the
        // sm row restore, and five full-width mobile actions.
        $this->assertSame(5, substr_count($html, 'poster-row'));
        $this->assertSame(5, substr_count($html, 'flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4'));
        $this->assertSame(5, substr_count($html, 'w-full justify-center sm:w-auto'));
    }
}
