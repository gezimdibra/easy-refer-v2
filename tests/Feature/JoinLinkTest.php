<?php

namespace Tests\Feature;

use App\Models\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * QRK-06 (smart-build-queue.md §5, Train 3): the short link.
 * Every business has a stored, stable, unique slug; /join/{slug}
 * sends the customer to the business's counter code landing;
 * an unknown slug answers the designed 404; every print artifact
 * carries the REF-POSTER-02j fallback line with the address
 * rendered from the app's own base URL (the one config point),
 * so the printed address always resolves from the deployment
 * the poster was printed from.
 */
class JoinLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_link_redirects_to_the_counter_code_landing(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers']);
        $codes = QrCode::ensurePlacements($business);

        $slug = $business->ensureJoinSlug();
        $this->assertSame('harbor-street-barbers', $slug);

        $response = $this->get(route('join.show', ['slug' => $slug]));
        $response->assertRedirect(route('refer.landing', ['token' => $codes['counter']->plainToken()]));

        // And the redirect target is a live landing.
        $this->get($response->headers->get('Location'))->assertOk();
    }

    public function test_slug_is_stable_across_a_business_rename(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers']);
        QrCode::ensurePlacements($business);
        $slug = $business->ensureJoinSlug();

        $business->forceFill(['name' => 'Harbor Street Barbers and Spa'])->save();

        $this->assertSame($slug, $business->fresh()->ensureJoinSlug());
        $this->assertSame($slug, $business->fresh()->slug);
        $this->get(route('join.show', ['slug' => $slug]))->assertRedirect();
    }

    public function test_slugs_are_unique_across_businesses(): void
    {
        $first = World::business(['name' => 'Harbor Street Barbers']);
        $second = World::business(['name' => 'Harbor Street Barbers']);

        $firstSlug = $first->ensureJoinSlug();
        $secondSlug = $second->ensureJoinSlug();

        $this->assertSame('harbor-street-barbers', $firstSlug);
        $this->assertNotSame($firstSlug, $secondSlug);
        $this->assertStringStartsWith('harbor-street-barbers', $secondSlug);
    }

    public function test_unknown_slug_answers_the_designed_404(): void
    {
        $this->get(route('join.show', ['slug' => 'no-such-store']))
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('We could not find the page you were looking for.');
    }

    public function test_print_artifacts_render_the_cant_scan_line(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers']);
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);
        $slug = $business->ensureJoinSlug();

        // The printed address is the join URL without its scheme,
        // rendered from the app's own address at print time
        // (REF-POSTER-02j; the coordinator's QRK-06 ruling).
        $printed = preg_replace('#^https?://#', '', route('join.show', ['slug' => $slug]));
        $line = "Can't scan? Go to {$printed}";

        // Amendment 31 [ON-QR-1]: 'screen' is retired and 404s;
        // the five surviving artifacts carry the line.
        foreach (['counter', 'window', 'mini', 'tent', 'receipt'] as $variant) {
            $html = $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => $variant]))->assertOk()->getContent();
            $this->assertStringContainsString($line, $html, "The {$variant} poster is missing the fallback line: {$line}");
        }
    }
}
