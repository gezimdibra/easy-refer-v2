<?php

namespace Tests\Feature\Flow;

use App\Models\AuditLog;
use App\Models\Business;
use App\Services\Share\OgCardRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RS-N1 (Train B, Amendment 39): per-share-link Open Graph
 * previews for the friend link. The preview asserts a business
 * identity and a friend reward, and both assertions are decided
 * by flowIsLive() at crawler fetch time: a live business gets
 * the full card (Variant A, Ink field); a paused, closed,
 * setup-incomplete, or disabled business gets NO reward preview,
 * in the page tags or at the image route, even when handed a
 * version hash minted while it was active. The image is per
 * business (business name and reward text only, cached under a
 * content version hash); the sender's first name lives in
 * og:title only, never in the image or the description. A share
 * link has no expiry, so no date or deadline appears anywhere
 * in the preview (the research sweep's expiry sub-item was
 * struck in the design pass).
 */
class OgPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function friendPage(Business $business, string $first = 'Marcus', string $last = 'Webb'): string
    {
        $referrer = World::customer('+12125550999', $first, $last);
        [, $shareToken] = World::membership($business, $referrer);

        return $this->get(route('refer.friend', ['token' => $shareToken]))
            ->assertOk()
            ->getContent();
    }

    private function ogImageUrl(string $html): string
    {
        $this->assertMatchesRegularExpression('/property="og:image" content="([^"]+)"/', $html);
        preg_match('/property="og:image" content="([^"]+)"/', $html, $m);

        return html_entity_decode($m[1]);
    }

    public function test_a_live_business_friend_page_carries_the_full_tag_set(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers']);
        $html = $this->friendPage($business);
        $head = substr($html, 0, strpos($html, '</head>'));

        $this->assertStringContainsString('property="og:type" content="website"', $head);
        $this->assertStringContainsString('property="og:title" content="Marcus sent you a reward at Harbor Street Barbers"', $head);
        $this->assertStringContainsString('property="og:description" content="Claim $10 off your first visit at Harbor Street Barbers. Claim it here, then show it when you visit."', $head);
        $this->assertStringContainsString('property="og:image:width" content="1200"', $head);
        $this->assertStringContainsString('property="og:image:height" content="630"', $head);
        $this->assertStringContainsString('property="og:image:alt" content="Harbor Street Barbers: $10 off your first visit"', $head);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $head);
        // First name only: no last name, no phone fragment,
        // anywhere in the head.
        $this->assertStringNotContainsString('Webb', $head);
        $this->assertStringNotContainsString('5550999', $head);
        // No expiry exists on a share link; none may be printed.
        $this->assertStringNotContainsString('expires', $head);
        // The layout's noindex discipline is untouched.
        $this->assertStringContainsString('name="robots" content="noindex"', $head);

        $imageUrl = $this->ogImageUrl($html);
        $this->assertStringContainsString('/refer/og/harbor-street-barbers/', $imageUrl);
        // The share token never reaches the image URL.
        $this->assertStringNotContainsString('token', $imageUrl);
    }

    public function test_the_image_route_serves_a_1200x630_png_for_a_live_business(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers']);
        $url = $this->ogImageUrl($this->friendPage($business));

        $response = $this->get($url);
        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $bytes = $response->getContent();
        $this->assertLessThan(600 * 1024, strlen($bytes), 'under the WhatsApp comfort limit');
        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image);
        $this->assertSame(1200, imagesx($image));
        $this->assertSame(630, imagesy($image));
    }

    public function test_paused_closed_and_setup_incomplete_businesses_get_no_reward_preview(): void
    {
        $paused = World::business(['name' => 'Paused Cuts', 'slug' => 'paused-cuts', 'billing_status' => 'past_due']);
        Carbon::setTestNow(now()->subDays(30));
        AuditLog::write('billing.past_due', null, [], $paused, null);
        Carbon::setTestNow(null);

        $closed = World::business(['name' => 'Closed Cuts', 'slug' => 'closed-cuts', 'status' => 'closed', 'closed_at' => now()]);
        // RE-DERIVED (activation redesign, 2026-10-30): the
        // setup-incomplete billing state left the gate —
        // billing_status is no longer an activation input, so
        // that business is LIVE on its allowance and DOES get
        // a reward preview (asserted positively below). The
        // no-preview list gains the new inactive cause in its
        // place: allowance exhausted, no card.
        $setup = World::business(['name' => 'Setup Cuts', 'slug' => 'setup-cuts', 'billing_status' => 'setup_incomplete']);
        $setupReferrer = World::customer('+12125550998', 'Marcus', 'Webb');
        [, $setupShare] = World::membership($setup, $setupReferrer);
        $setupHtml = $this->get(route('refer.friend', ['token' => $setupShare]))->getContent();
        $this->assertStringContainsString('og:image', $setupHtml, 'a cardless business on its allowance is live and previews');

        $exhausted = World::business(['name' => 'Spent Cuts', 'slug' => 'spent-cuts', 'billing_status' => 'none']);
        $spentReferrer = World::customer('+12125550997', 'Marcus', 'Webb');
        [$spentMembership] = World::membership($exhausted, $spentReferrer);
        for ($i = 0; $i < 5; $i++) {
            $spentFriend = World::customer('+1212555096'.(string) $i, 'Fri', 'End');
            [$spentReferral] = World::referral($spentMembership, $spentFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $exhausted->id,
                'referral_id' => $spentReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
        $disabled = World::business(['name' => 'Disabled Cuts', 'slug' => 'disabled-cuts', 'status' => 'disabled']);

        foreach ([$paused, $closed, $exhausted, $disabled] as $business) {
            $referrer = World::customer('+12125550999', 'Marcus', 'Webb');
            [, $shareToken] = World::membership($business, $referrer);
            $html = $this->get(route('refer.friend', ['token' => $shareToken]))->getContent();
            $this->assertStringNotContainsString('og:image', $html, $business->name.' leaked a reward preview');
            $this->assertStringNotContainsString('og:title', $html, $business->name.' leaked a reward title');
        }
    }

    public function test_the_image_route_rechecks_state_and_never_serves_a_reward_card_for_a_paused_business(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers']);
        $url = $this->ogImageUrl($this->friendPage($business));
        $liveBytes = $this->get($url)->assertOk()->getContent();

        // The business pauses after the card was minted and cached.
        $business->forceFill(['billing_status' => 'past_due'])->save();
        Carbon::setTestNow(now()->subDays(30));
        AuditLog::write('billing.past_due', null, [], $business->fresh(), null);
        Carbon::setTestNow(null);

        $afterBytes = $this->get($url)->assertOk()->getContent();
        $this->assertNotSame($liveBytes, $afterBytes, 'a paused business must not serve its reward card');

        // The degraded card is the generic brand card: identical
        // for a different paused business, so it asserts nothing
        // about either business.
        $other = World::business(['name' => 'Other Place', 'slug' => 'other-place', 'status' => 'closed', 'closed_at' => now()]);
        $otherUrl = route('refer.og.card', ['slug' => 'other-place', 'version' => app(OgCardRenderer::class)->versionFor($other)]);
        $this->assertSame($afterBytes, $this->get($otherUrl)->assertOk()->getContent());
    }

    public function test_mutated_hashes_and_unknown_slugs_get_no_card(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers']);
        $renderer = app(OgCardRenderer::class);
        $version = $renderer->versionFor($business);

        $this->get(route('refer.og.card', ['slug' => 'harbor-street-barbers', 'version' => $version]))->assertOk();
        $mutated = ($version[0] === 'a' ? 'b' : 'a').substr($version, 1);
        $this->get(route('refer.og.card', ['slug' => 'harbor-street-barbers', 'version' => $mutated]))->assertNotFound();
        $this->get(route('refer.og.card', ['slug' => 'no-such-business', 'version' => $version]))->assertNotFound();
        // Sequential probing by business id is not a route.
        $this->get('/refer/og/'.$business->id.'/'.$version.'.png')->assertNotFound();
    }

    public function test_editing_the_reward_text_changes_the_image_url_and_retires_the_old_one(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers']);
        $oldUrl = $this->ogImageUrl($this->friendPage($business));
        $this->get($oldUrl)->assertOk();

        $business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $newUrl = $this->ogImageUrl($this->friendPage($business->fresh()));

        $this->assertNotSame($oldUrl, $newUrl);
        $this->get($oldUrl)->assertNotFound();
        $this->get($newUrl)->assertOk();
    }

    public function test_the_longest_reward_text_renders_inside_the_canvas_truncated_to_a_prefix(): void
    {
        $long = str_repeat('W', 120);
        $business = World::business([
            'name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers',
            'friend_reward_text' => $long,
        ]);
        $url = $this->ogImageUrl($this->friendPage($business));
        $bytes = $this->get($url)->assertOk()->getContent();
        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image);
        $this->assertSame([1200, 630], [imagesx($image), imagesy($image)]);

        // The layout helper truncates with an ellipsis and adds
        // no words: the rendered string is a prefix of the
        // composer's own output.
        $headline = (string) $business->friendRewardHeadline();
        $lines = OgCardRenderer::fittedRewardLines($headline);
        $joined = implode(' ', $lines);
        $this->assertStringEndsWith("\u{2026}", $joined);
        // Lines are visual chunks of one string: with the line
        // breaks removed, what renders is a strict prefix of the
        // composer's output (plus the ellipsis), nothing added.
        $flat = str_replace(' ', '', rtrim($joined, "\u{2026}"));
        $this->assertStringStartsWith($flat, str_replace(' ', '', $headline));
    }

    public function test_a_business_with_no_reward_text_gets_the_fallback_card_and_wording(): void
    {
        // RE-SCOPED by Train F2 (Amendment 40), flagged for the
        // two-key reviewer: this pin's original premise — a
        // business live on its card with no reward text, whose
        // friend page renders the OG fallback wording — is the
        // F-2 state itself. The activation gate makes it
        // unreachable: the friend page now answers not_active,
        // and Amendment 39's own valid-state rule (OG entries
        // render ONLY when the flow is live; the image route
        // serves the generic brand card [OG-06] otherwise)
        // governs what remains. The fallback wording stays in
        // the composer/renderer as defense in depth. What this
        // pin now asserts is the post-gate truth:
        $business = World::business([
            'name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers',
            'friend_reward_text' => null, 'referrer_reward_text' => null,
        ]);
        $referrer = World::customer('+12125550999', 'Marcus', 'Webb');
        [, $shareToken] = World::membership($business, $referrer);

        $response = $this->get(route('refer.friend', ['token' => $shareToken]));
        $response->assertNotFound();
        $response->assertSee('This code is not active');
        $response->assertDontSee('og:image', false);
        $response->assertDontSee('A reward from a friend');

        // The image route, handed a correctly-formed version
        // URL for the textless business, serves the generic
        // brand card [OG-06], never a reward card.
        $version = app(\App\Services\Share\OgCardRenderer::class)->versionFor($business);
        $bytes = $this->get(route('refer.og.card', ['slug' => $business->slug, 'version' => $version]))
            ->assertOk()->getContent();
        $this->assertNotFalse(imagecreatefromstring($bytes));
        // RE-DERIVED (activation redesign, 2026-10-30): the
        // old comparator (billing_status 'none', texts set) is
        // LIVE under the allowance gate and serves its own
        // reward card; the generic-card comparator is another
        // textless business.
        $generic = World::business(['billing_status' => 'none', 'slug' => 'generic-place', 'friend_reward_text' => null, 'referrer_reward_text' => null]);
        $genericBytes = $this->get(route('refer.og.card', [
            'slug' => $generic->slug,
            'version' => app(\App\Services\Share\OgCardRenderer::class)->versionFor($generic),
        ]))->assertOk()->getContent();
        $this->assertSame($genericBytes, $bytes);
    }

    public function test_near_identical_business_names_render_distinct_cards(): void
    {
        $a = World::business(['name' => 'Harbor Street Barbers', 'slug' => 'harbor-street-barbers']);
        $b = World::business(['name' => 'Harbor Street Barberz', 'slug' => 'harbor-street-barberz']);

        $bytesA = $this->get($this->ogImageUrl($this->friendPage($a)))->assertOk()->getContent();
        $bytesB = $this->get($this->ogImageUrl($this->friendPage($b)))->assertOk()->getContent();

        $this->assertNotSame($bytesA, $bytesB);
    }

    public function test_fonts_resolve_the_shipped_in_tree_pair_without_any_env_override(): void
    {
        $previous = getenv('OG_CARD_FONT_DIR');
        putenv('OG_CARD_FONT_DIR');
        try {
            $fonts = OgCardRenderer::fonts();
        } finally {
            putenv($previous === false ? 'OG_CARD_FONT_DIR' : 'OG_CARD_FONT_DIR='.$previous);
        }

        // The DejaVu pair ships in the tree (resources/fonts/og)
        // so a host with no system TTF — the r42 Gate 1 staging
        // failure, where the target carried only urw-base35
        // Type1 fonts — still renders real type. The in-tree
        // candidate outranks the system candidates: this machine
        // HAS a system DejaVu pair, and fonts() must still
        // return the in-tree paths. (Proven the hard way too, in
        // the fix lane: with the system font dirs hidden behind
        // a tmpfs mount, fonts() returned these same paths.)
        $this->assertSame(base_path('resources/fonts/og/DejaVuSans-Bold.ttf'), $fonts['bold']);
        $this->assertSame(base_path('resources/fonts/og/DejaVuSans.ttf'), $fonts['regular']);
        // GD can actually parse and measure both faces.
        foreach ($fonts as $font) {
            $this->assertFileExists($font);
            $box = imagettfbbox(40, 0, $font, 'Easy Refer');
            $this->assertNotFalse($box);
            $this->assertGreaterThan(0, $box[2] - $box[0]);
        }
    }

    public function test_a_pairless_env_dir_falls_through_to_the_in_tree_pair(): void
    {
        $previous = getenv('OG_CARD_FONT_DIR');
        $dir = sys_get_temp_dir().'/og-fonts-empty-'.uniqid();
        mkdir($dir);
        try {
            putenv('OG_CARD_FONT_DIR='.$dir);
            $fonts = OgCardRenderer::fonts();
            $this->assertSame(base_path('resources/fonts/og/DejaVuSans-Bold.ttf'), $fonts['bold']);
            $this->assertSame(base_path('resources/fonts/og/DejaVuSans.ttf'), $fonts['regular']);
        } finally {
            putenv($previous === false ? 'OG_CARD_FONT_DIR' : 'OG_CARD_FONT_DIR='.$previous);
            rmdir($dir);
        }
    }

    public function test_the_rendered_card_is_drawn_with_the_discovered_ttf_not_the_gd_builtin_font(): void
    {
        $serif = '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf';
        $serifBold = '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf';
        if (! is_file($serif) || ! is_file($serifBold)) {
            $this->markTestSkipped('No second local typeface to contrast the render against.');
        }

        $previous = getenv('OG_CARD_FONT_DIR');
        $dir = sys_get_temp_dir().'/og-fonts-'.uniqid();
        mkdir($dir);
        $renderer = app(OgCardRenderer::class);
        $renderGeneric = function () use ($renderer): string {
            foreach (File::glob(storage_path('app/og-cards/generic-*.png')) as $cached) {
                File::delete($cached);
            }

            return $renderer->genericCardPng();
        };

        try {
            putenv('OG_CARD_FONT_DIR');
            $inTreeBytes = $renderGeneric();

            // The same card drawn through a different real TTF
            // pair (the serif face carried under the DejaVu
            // names) must produce different bytes: if the
            // renderer ever fell back to GD's built-in font, the
            // discovered font files would have no effect on the
            // render at all.
            copy($serifBold, $dir.'/DejaVuSans-Bold.ttf');
            copy($serif, $dir.'/DejaVuSans.ttf');
            putenv('OG_CARD_FONT_DIR='.$dir);
            $this->assertNotSame($inTreeBytes, $renderGeneric());

            // And pointing the override at the shipped directory
            // reproduces the default (no-override) render exactly.
            putenv('OG_CARD_FONT_DIR='.base_path('resources/fonts/og'));
            $this->assertSame($inTreeBytes, $renderGeneric());
        } finally {
            putenv($previous === false ? 'OG_CARD_FONT_DIR' : 'OG_CARD_FONT_DIR='.$previous);
            File::deleteDirectory($dir);
        }
    }

    public function test_other_flow_pages_stay_tag_free(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $html = $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('og:', $html);
        $this->assertStringNotContainsString('twitter:card', $html);
    }
}
