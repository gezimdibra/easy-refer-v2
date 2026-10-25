<?php

namespace Tests\Feature\Owner;

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

        // One print action per variant (screen, counter, window),
        // each opening its poster page in print mode.
        $this->assertSame(3, substr_count($page->getContent(), 'Print poster'));
        foreach (['screen', 'counter', 'window'] as $variant) {
            $page->assertSee('/qr/poster/'.$variant.'?print=1', false);
        }

        // No poster link still promises a download that never comes.
        $page->assertDontSee('> Download</a>', false);
    }
}
