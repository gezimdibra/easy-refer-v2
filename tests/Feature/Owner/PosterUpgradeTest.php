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
 * every sheet variant at pass 2; the steps strip runs on
 * screen + counter only (the window keeps six-feet
 * discipline); the YOUR REWARD card (label + the referrer
 * amount, largest on the page) runs on counter + mini-poster
 * only; the code stays pure black on white inside an amber
 * halo frame (the frame carries the brand, never the code);
 * the closer is the trust line while no boost window exists
 * (addendum A2: urgency renders only when it is true).
 *
 * World defaults: friend "$10 off your first visit",
 * referrer "$10 off", so the composed give/get line is
 * "Give $10 off. Get $10 off when your friend visits."
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

        $this->assertStringContainsString('Give $10 off. Get $10 off when your friend visits.', $text);
        $this->assertStringContainsString('1. Scan · 2. Send it to a friend · 3. You both get rewarded', $text);
        $this->assertStringContainsString('YOUR REWARD', $text);
        $this->assertStringContainsString('Free to join. No app to install.', $text);

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
        $this->assertStringContainsString('Give $10 off. Get $10 off when your friend visits.', $text);
        $this->assertStringNotContainsString('Send it to a friend', $text);
        $this->assertStringNotContainsString('YOUR REWARD', $text);
        $this->assertStringNotContainsString('No app to install', $text);
        $response->assertSee('poster-halo', false);
    }

    public function test_screen_poster_has_steps_but_no_reward_card(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'screen'));
        $response->assertOk();
        $text = $this->text($response);

        $this->assertStringContainsString('Give $10 off. Get $10 off when your friend visits.', $text);
        $this->assertStringContainsString('1. Scan · 2. Send it to a friend · 3. You both get rewarded', $text);
        $this->assertStringContainsString('Free to join. No app to install.', $text);
        $this->assertStringNotContainsString('YOUR REWARD', $text);
    }

    public function test_reward_card_and_give_get_follow_the_configured_texts(): void
    {
        $business = World::business(['friend_reward_text' => '$10 off', 'referrer_reward_text' => '']);
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr.poster', 'counter'));
        $response->assertOk();
        $text = $this->text($response);

        // Friend-only fallback line, and no reward card: the
        // card is the referrer's configured reward or nothing.
        $this->assertStringContainsString('Share it with a friend. You get a reward when they visit.', $text);
        $this->assertStringNotContainsString('YOUR REWARD', $text);
    }

    public function test_no_boost_copy_appears_while_boost_windows_do_not_exist(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        // All six variants (Train 1 fix round F-07): an absence
        // pin must enumerate the full surface set it claims, or
        // the unlisted variants drift silently.
        foreach (['screen', 'counter', 'window', 'mini', 'tent', 'receipt'] as $variant) {
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

        // Face A: headline + give/get + code. Face B: business
        // + steps + trust + code. Both faces must be scannable
        // from the seat they face.
        $this->assertStringContainsString('Get $10 off your first visit', $text);
        $this->assertStringContainsString('Give $10 off. Get $10 off when your friend visits.', $text);
        $this->assertStringContainsString('Business 1', $text);
        $this->assertStringContainsString('1. Scan · 2. Send it to a friend · 3. You both get rewarded', $text);
        $this->assertStringContainsString('Free to join. No app to install.', $text);
        $this->assertSame(2, substr_count($text, 'Scan to join'));
        $this->assertSame(2, substr_count($response->getContent(), 'poster-halo'));
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
        $this->assertSame(2, substr_count($text, 'Get $10 off your first visit'));
        $this->assertSame(2, substr_count($text, 'Give $10 off. Get $10 off when your friend visits.'));
        $this->assertSame(2, substr_count($text, 'Scan to join'));
        $this->assertSame(2, substr_count($text, 'Free to join. No app to install.'));
        $response->assertSee('width: 85mm', false);
        $response->assertSee('height: 55mm', false);
        $response->assertSee('width: 80mm', false);
        $response->assertSee('size: 85mm 55mm', false);
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
