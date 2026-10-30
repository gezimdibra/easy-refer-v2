<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Amendment 58 [REF-POSTER-04] — the Finding 4 grep pin
 * (owner ruling 2026-10-29): "join" and "program" are
 * BANNED from customer-facing print headlines and poster
 * CTAs. This test renders every print variant in every
 * reward configuration (UNIFIED, SPLIT, UNSET) and greps
 * the rendered sheets: no banned word in any headline or
 * CTA position, the picked headline present verbatim
 * (tags stripped, whitespace normalized — exactly the
 * composer output), the CTA reading "Scan to share", the
 * trust line carrying "No app to install." with no "Free
 * to join" before it, the band closing on "Rewards for
 * sharing.", and the steps strip echoing the headline's
 * verbs. The onboarding Poster step and the QR page print
 * previews embed this same poster view in iframes, so the
 * strings pinned here are the strings the previews show.
 */
class PosterCopyRulingTest extends TestCase
{
    use RefreshDatabase;

    private function text($response): string
    {
        $text = html_entity_decode(strip_tags($response->getContent()));

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configurations(): array
    {
        return [
            'unified' => ['friend_reward_text' => '$10 off', 'referrer_reward_text' => null, 'reward_split' => false],
            'split' => ['friend_reward_text' => '$10 off your first cut', 'referrer_reward_text' => '$10 off your next cut', 'reward_split' => true],
            'unset' => ['friend_reward_text' => null, 'referrer_reward_text' => null, 'reward_split' => false],
        ];
    }

    public function test_no_banned_word_prints_in_any_headline_or_cta_position(): void
    {
        foreach ($this->configurations() as $label => $overrides) {
            $business = World::business($overrides);
            $owner = World::owner($business);
            World::qr($business);

            foreach (['counter', 'window', 'mini', 'tent', 'receipt'] as $variant) {
                $text = $this->text($this->actingAs($owner)->get(route('owner.qr.poster', $variant)));

                foreach (['Scan to join', 'Free to join', 'referral program', 'Scan this code to join', 'Scan this card to join'] as $banned) {
                    $this->assertStringNotContainsString(
                        $banned,
                        $text,
                        "[{$label}/{$variant}] still prints the banned string \"{$banned}\"."
                    );
                }
                // The typed fallback address keeps its /join/
                // slug (the ruling bans the WORDING, not the
                // URL); it is removed before the word grep so
                // the pin judges printed words only.
                $wordsOnly = (string) preg_replace('#\S*/join/\S*#', '', $text);
                $this->assertSame(
                    0,
                    preg_match('/\bjoin\b/i', $wordsOnly),
                    "[{$label}/{$variant}] prints the word \"join\" somewhere on the sheet."
                );
                $this->assertSame(
                    0,
                    preg_match('/\bprogram\b/i', $text),
                    "[{$label}/{$variant}] prints the word \"program\" somewhere on the sheet."
                );

                // The picked headline, verbatim, on every sheet.
                $this->assertStringContainsString(
                    $business->posterHeadline(),
                    $text,
                    "[{$label}/{$variant}] does not carry the composed poster headline."
                );

                // The CTA under every code echoes the headline verb.
                $this->assertStringContainsString('Scan to share', $text, "[{$label}/{$variant}] lost the Scan to share CTA.");
            }
        }
    }

    public function test_steps_trust_and_band_carry_the_ruled_wording(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $counter = $this->text($this->actingAs($owner)->get(route('owner.qr.poster', 'counter')));
        $this->assertStringContainsString('1. Scan · 2. Share with a friend · 3. You both get rewarded', $counter);
        $this->assertStringContainsString('No app to install.', $counter);
        $this->assertStringContainsString('Rewards for sharing.', $counter);
        // The business name still appears, cleanly, in the
        // header strip — the headline no longer mangles it
        // into a double possessive.
        $this->assertStringContainsString($business->name, $counter);
        $this->assertStringNotContainsString("'s referral", $counter);

        $tent = $this->text($this->actingAs($owner)->get(route('owner.qr.poster', 'tent')));
        $this->assertStringContainsString('1. Scan · 2. Share with a friend · 3. You both get rewarded', $tent);
        $this->assertStringContainsString('No app to install.', $tent);
        $this->assertSame(2, substr_count($tent, 'Scan to share'), 'Both tent faces carry the CTA.');

        $receipt = $this->text($this->actingAs($owner)->get(route('owner.qr.poster', 'receipt')));
        $this->assertStringContainsString('Scan this card, then share it with a friend.', $receipt);
        $this->assertStringContainsString('No app to install.', $receipt);
    }

    public function test_the_qr_landing_echoes_the_poster_headline_in_both_configurations(): void
    {
        // PROMISE ECHO (Amendment 58): the page the printed
        // code opens carries the poster's exact headline —
        // same reward, same verbs — in both configurations.
        foreach ($this->configurations() as $label => $overrides) {
            if ($label === 'unset') {
                continue; // the unset landing is pinned below by its own headline
            }
            $business = World::business($overrides);
            [, $qrToken] = World::qr($business);

            $this->get(route('refer.landing', ['token' => $qrToken]))
                ->assertOk()
                ->assertSee($business->posterHeadline(), false);
        }

        // UNSET: there is no live landing to echo to — a
        // business with no reward text is not flow-active,
        // so its code's landing is the designed generic page
        // (the unset poster's audience is the owner at the
        // Poster step, before activation). The moment a
        // reward is set, the two configurations above are
        // the states a scanner can ever meet.
        $business = World::business($this->configurations()['unset']);
        [, $qrToken] = World::qr($business);
        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertNotFound()
            ->assertDontSee('referral program');
    }
}
