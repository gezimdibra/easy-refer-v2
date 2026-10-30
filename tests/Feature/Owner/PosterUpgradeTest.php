<?php

namespace Tests\Feature\Owner;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * QRK-02: the poster upgrade (qr-growth-kit-design.md
 * Sections 2-3 + coordinator addendum A1/A3; copy inventory
 * REF-POSTER-02). The give/get line (QRK-01's composer) joins
 * every sheet variant at pass 2; the steps strip runs on the
 * counter only (the window keeps six-feet
 * discipline); the YOUR REWARD card (label + the referrer
 * amount, largest on the page) runs on counter + mini-poster
 * only; the code stays pure black on white inside an amber
 * halo frame (the frame carries the brand, never the code);
 * the closer is the trust line while no boost window exists
 * (addendum A2: urgency renders only when it is true).
 *
 * World defaults: friend "$10 off your first visit",
 * referrer "$10 off", split on.
 *
 * Amendment 58 [REF-POSTER-04] updated this file's pins:
 * the give/get sentence is the HEADLINE now, composed by
 * Business::posterHeadline() ("Give a friend $10 off your
 * first visit. Get $10 off when they visit." for the World
 * defaults), so the old pass-2 give/get pins ("Give $10
 * off. Get $10 off when your friend visits.") moved to the
 * headline string; the steps strip echoes the headline's
 * share verb; the trust line is "No app to install." with
 * "Free to join" banned off the sheets; the CTA is "Scan
 * to share". Business::giveGetLine() itself is unchanged
 * and still pinned by GiveGetLineTest.
 */
class PosterUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function text($response): string
    {
        $text = html_entity_decode(strip_tags($response->getContent()));

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    public function test_counter_poster_carries_the_full_pass_two_stack(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'counter'));
        $response->assertOk();
        $text = $this->text($response);

        $this->assertStringContainsString('Give a friend $10 off your first visit. Get $10 off when they visit.', $text);
        $this->assertStringContainsString('1. Scan · 2. Share with a friend · 3. You both get rewarded', $text);
        $this->assertStringContainsString('YOUR REWARD', $text);
        $this->assertStringContainsString('No app to install.', $text);

        // The amber halo frame is present in the markup; the
        // code image itself is the unchanged SVG route.
        $response->assertSee('poster-halo', false);
        $response->assertSee(route('owner.qr.image'), false);
    }

    public function test_window_poster_keeps_six_feet_discipline(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'window'));
        $response->assertOk();
        $text = $this->text($response);

        // Pass 1 + the give/get decision line, nothing finer:
        // no steps strip, no reward card, no trust line.
        $this->assertStringContainsString('Give a friend $10 off your first visit. Get $10 off when they visit.', $text);
        $this->assertStringNotContainsString('Share with a friend', $text);
        $this->assertStringNotContainsString('YOUR REWARD', $text);
        $this->assertStringNotContainsString('No app to install', $text);
        $response->assertSee('poster-halo', false);
    }

    public function test_screen_poster_variant_is_retired(): void
    {
        // MOVED (Amendment 31 [ON-QR-1]): this test pinned the
        // screen poster's pass-2 stack; the variant is retired
        // and stops resolving, so the pin is now its 404. The
        // counter keeps the steps strip it shared with screen
        // (pinned in test_counter_poster_carries_the_full_pass_two_stack).
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $this->actingAs($owner)->get(route('owner.qr.poster', 'screen'))->assertNotFound();
    }

    public function test_reward_card_and_give_get_follow_the_configured_texts(): void
    {
        // OR-1 §1.3 D2: the friend-only population IS the
        // UNIFIED configuration (flag off, referrer NULL). The
        // YOUR REWARD card now renders with the unified value —
        // before OR-1 it keyed on the referrer column and a
        // fallback business printed no card at all.
        // Amendment 58: the headline is the UNIFIED poster
        // headline for this configuration.
        $business = World::business(['friend_reward_text' => '$10 off', 'referrer_reward_text' => '', 'reward_split' => false]);
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'counter'));
        $response->assertOk();
        $text = $this->text($response);

        $this->assertStringContainsString('Share with a friend, you both get $10 off when they visit.', $text);
        $this->assertStringContainsString('YOUR REWARD', $text);
    }

    public function test_no_boost_copy_appears_while_boost_windows_do_not_exist(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        // All five surviving variants (Train 1 fix round
        // F-07): an absence pin must enumerate the full surface
        // set it claims, or the unlisted variants drift
        // silently. (Amendment 31 [ON-QR-1] retired 'screen'.)
        foreach (['counter', 'window', 'mini', 'tent', 'receipt'] as $variant) {
            $text = $this->text($this->actingAs($owner)->get(route('owner.qr.poster', $variant)));

            $this->assertStringNotContainsString('Limited Time', $text, "Variant [{$variant}] printed fake scarcity.");
            $this->assertStringNotContainsString('Double Rewards', $text, "Variant [{$variant}] printed a boost line with no boost active.");
            $this->assertStringNotContainsString('Miss Out', $text, "Variant [{$variant}] printed fake scarcity.");
        }
    }

    public function test_table_tent_renders_both_faces_with_a_code_on_each(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'tent'));
        $response->assertOk();
        $text = $this->text($response);

        // Face A: headline + code. Face B: business
        // + steps + trust + code. Both faces must be scannable
        // from the seat they face.
        // Amendment 58: the tent headline IS the give/get
        // sentence (posterHeadline), so no separate give/get
        // line renders under it; the friend reward text still
        // appears inside the headline verbatim, and the old
        // "Get {reward-alone}" construction stays absent.
        $this->assertStringContainsString('$10 off your first visit', $text);
        $this->assertStringNotContainsString('Get $10 off your first visit', $text);
        $this->assertStringContainsString('Give a friend $10 off your first visit. Get $10 off when they visit.', $text);
        $this->assertStringContainsString('Business 1', $text);
        $this->assertStringContainsString('1. Scan · 2. Share with a friend · 3. You both get rewarded', $text);
        $this->assertStringContainsString('No app to install.', $text);
        $this->assertSame(2, substr_count($text, 'Scan to share'));
        // Counted on the class attribute: the print stylesheet
        // also names the class (R4-U5's flat-halo rule).
        $this->assertSame(2, substr_count($response->getContent(), 'class="poster-halo'));
        $response->assertSee('size: A5 landscape', false);
        $response->assertSee('w-[220px]', false);
        $response->assertSee('poster-page-break', false);
    }

    public function test_receipt_card_renders_its_exact_stack_at_card_and_strip_sizes(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'receipt'));
        $response->assertOk();
        $text = $this->text($response);

        $this->assertSame(2, substr_count($text, 'Thanks for coming in.'));
        // Release 37: both receipt sheets (card + strip) carry the
        // headline with no "Get " prefix.
        $this->assertSame(2, substr_count($text, '$10 off your first visit'));
        $this->assertSame(0, substr_count($text, 'Get $10 off your first visit'));
        $this->assertSame(2, substr_count($text, 'Give a friend $10 off your first visit. Get $10 off when they visit.'));
        $this->assertSame(2, substr_count($text, 'Scan to share'));
        // Amendment 41 (RC-3 Candidate A): the trust line is
        // dropped from the CARD sheet only to fund the business
        // name + buyer instruction lines; the strip keeps it, so
        // the receipt view now carries it exactly once.
        $this->assertSame(1, substr_count($text, 'No app to install.'));
        $response->assertSee('width: 85mm', false);
        $response->assertSee('height: 55mm', false);
        $response->assertSee('width: 80mm', false);
        $response->assertSee('size: 85mm 55mm', false);
    }

    public function test_poster_headline_matches_the_composer_on_every_variant(): void
    {
        // Amendment 58 [REF-POSTER-04] supersedes the release
        // 37 form of this test: the poster headline is no
        // longer the reward-alone composer output (with its
        // first-visit suffix) — it is the give/get headline
        // composed by Business::posterHeadline(), and every
        // variant must carry that output verbatim. The
        // friend-side composer (friendRewardHeadline, suffix
        // logic included) still governs the flow's friend
        // pages and is pinned there.
        $business = World::business(['friend_reward_text' => '$10 off']);
        $owner = World::owner($business);
        World::qr($business);

        $this->assertSame('Give a friend $10 off. Get $10 off when they visit.', $business->posterHeadline());

        foreach (['counter', 'window', 'mini', 'tent', 'receipt'] as $variant) {
            $text = $this->text($this->actingAs($owner)->get(route('owner.qr.poster', $variant)));

            $this->assertStringContainsString(
                $business->posterHeadline(),
                $text,
                "Variant [{$variant}] headline does not equal the poster composer output."
            );
        }
    }

    public function test_counter_poster_sets_its_page_size(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'counter'));
        $response->assertOk();
        $response->assertSee('@page', false);
        $response->assertSee('size: A5', false);
    }
}
