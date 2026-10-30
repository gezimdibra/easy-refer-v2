<?php

namespace Tests\Feature\Owner;

use App\Models\Business;
use App\Models\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * QF frame (gap pack 03, Variant A in full; copy Amendment 29
 * section 17.18 [QF-1]/[QF-2] RATIFIED verbatim). The MIX poster
 * section (Amendment 27) already landed in r26; these pins hold
 * the frame around it:
 *
 * QF-1 exactly one primary button on the whole page (Start
 *      here's "Print this poster"); the mini-poster is reachable
 *      from Also available only.
 * QF-2 the slim code utility panel "Your code on its own" below
 *      How redemption works: plain code image, the shipped
 *      code-only download and the copy-link joined field at
 *      secondary weight, no primary button inside.
 * QF-3 placements furniture: the by-spot panel keeps its intro
 *      line, "Totals are all time." appears exactly once, a spot
 *      with zero scans and zero joins reads "No scans yet", and
 *      the by-spot rows are the page's only stats home.
 * QF-4 the reward-texts truth strip "What your poster says": the
 *      friend text exactly as the poster prints it, the referrer
 *      text beneath when set, one secondary "Edit reward texts"
 *      action into Settings Rewards; the unset state renders
 *      [QF-1]'s empty-state line with the same link.
 * QF-5 "How redemption works" is the numbered three-step list
 *      [QF-2]; no information from the shipped paragraph is lost.
 * QF-6 final page order per Variant A, danger zone last with its
 *      modal untouched.
 */
class QrFrameTest extends TestCase
{
    use RefreshDatabase;

    private function qrHtml(Business $business): string
    {
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        return $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
    }

    /** The HTML between two markers (exclusive), asserting both exist in order. */
    private function between(string $html, string $from, string $to): string
    {
        $a = strpos($html, $from);
        $b = strpos($html, $to);
        $this->assertNotFalse($a, "Marker not found: {$from}");
        $this->assertNotFalse($b, "Marker not found: {$to}");
        $this->assertLessThan($b, $a, "Marker {$from} must precede {$to}.");

        return substr($html, $a, $b - $a);
    }

    public function test_qf1_exactly_one_primary_and_mini_only_in_also_available(): void
    {
        $html = $this->qrHtml(World::business());

        $this->assertSame(1, substr_count($html, 'btn-primary'), 'QF-1: the page renders exactly one primary button.');

        // The one primary is Start here's print action.
        $startHere = $this->between($html, 'id="start-here"', 'Share your code online');
        $this->assertStringContainsString('btn-primary', $startHere);
        $this->assertStringContainsString('Print this poster', $startHere);

        // The mini-poster's only print route on the page rides
        // the Also available row.
        $miniRoute = route('owner.qr.poster', ['variant' => 'mini', 'print' => 1]);
        $this->assertSame(1, substr_count($html, $miniRoute), 'QF-1: the mini-poster print route appears exactly once.');
        $also = $this->between($html, 'id="also-available"', 'id="how-redemption-works"');
        $this->assertStringContainsString($miniRoute, $also);
        $this->assertStringNotContainsString('btn-primary', $also);
    }

    public function test_qf2_code_utility_panel_below_redemption_at_secondary_weight(): void
    {
        $business = World::business();
        $html = $this->qrHtml($business);

        $panel = $this->between($html, 'id="code-on-its-own"', 'card-danger');

        $this->assertStringContainsString('Your code on its own', $panel);
        // The plain code image (the counter code, moderate size).
        $this->assertStringContainsString(route('owner.qr.image'), $panel);
        $this->assertMatchesRegularExpression('/<img[^>]+src="[^"]*\/qr\/image"/', $panel);
        // Shipped download words and behavior: the POST download form.
        $this->assertStringContainsString('Download code only (for your own designs)', $panel);
        $this->assertStringContainsString('action="'.route('owner.qr.download').'"', $panel);
        // The copy-link joined field, shipped words, the public URL.
        $this->assertStringContainsString('Copy link', $panel);
        $codes = QrCode::ensurePlacements($business);
        $this->assertStringContainsString(route('refer.landing', ['token' => $codes['counter']->plainToken()]), $panel);
        // Secondary weight only: no primary button inside.
        $this->assertStringNotContainsString('btn-primary', $panel);
        $this->assertStringContainsString('btn-secondary', $panel);

        // The panel sits below How redemption works (QF-6 order
        // is pinned in full by test_qf6).
        $this->assertLessThan(strpos($html, 'id="code-on-its-own"'), strpos($html, 'id="how-redemption-works"'));
    }

    public function test_qf3_placements_furniture_and_zero_state(): void
    {
        $html = $this->qrHtml(World::business());

        $panel = $this->between($html, 'id="by-spot"', 'id="also-available"');

        // Intro line (the MIX-anticipated by-spot intro, shipped).
        $this->assertStringContainsString(
            'Every spot gets its own code, so you can see which spot brings people in.',
            $panel
        );
        // The all-time footnote, exactly once on the whole page.
        $this->assertSame(1, substr_count($html, 'Totals are all time.'), 'QF-3: the footnote appears exactly once.');
        $this->assertStringContainsString('Totals are all time.', $panel);

        // Zero state: with no scan events every row reads
        // "No scans yet" and none renders the numeric form.
        $this->assertSame(4, substr_count($panel, 'No scans yet'), 'QF-3: each zero spot reads "No scans yet".');
        $this->assertStringNotContainsString('0 scans · 0 joins so far', $html);

        // Exactly one stats home: the numeric stats format lives
        // only inside the by-spot panel, and the old placements
        // table is nowhere on the page.
        $this->assertStringNotContainsString('Your codes by placement', $html);
        $this->assertStringNotContainsString('id="placements"', $html);
        $outside = str_replace($panel, '', $html);
        $this->assertDoesNotMatchRegularExpression('/\d+ scans · \d+ joins so far/', $outside, 'QF-3: stats render only in the by-spot rows.');
    }

    public function test_qf4_truth_strip_matches_the_poster_character_for_character(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $strip = $this->between($html, 'id="poster-says"', 'id="code-on-its-own"');

        $this->assertStringContainsString('What your poster says', $strip);

        // Extract the strip's friend text from its dedicated element.
        $this->assertSame(1, preg_match('/data-poster-friend-text>([^<]+)</', $strip, $m), 'QF-4: the strip friend element is missing.');
        $stripFriend = html_entity_decode($m[1]);
        $this->assertSame($business->friend_reward_text, $stripFriend, 'QF-4: the strip shows the stored friend text verbatim.');

        // The counter poster prints that same text, character for
        // character, as its headline. Release 35 removed the
        // generated "Get " prefix from the flow composer, and
        // release 37 removes it from the printed poster too: the
        // poster headline IS the composer output, with no prefix.
        $poster = $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'counter']))->assertOk()->getContent();
        $posterText = html_entity_decode((string) preg_replace('/\s+/', ' ', strip_tags($poster)));
        $this->assertStringContainsString($stripFriend, $posterText, 'QF-4: the poster headline carries the strip text verbatim.');
        $this->assertStringNotContainsString('Get '.$stripFriend, $posterText, 'QF-4: the poster headline must not prefix the strip text with "Get".');
        $this->assertSame($stripFriend, $business->friendRewardHeadline());

        // The referrer text rides beneath when set.
        $this->assertSame(1, preg_match('/data-poster-referrer-text>([^<]+)</', $strip, $r), 'QF-4: the strip referrer element is missing.');
        $this->assertSame($business->referrer_reward_text, html_entity_decode($r[1]));

        // One secondary action into Settings Rewards.
        $this->assertStringContainsString('Edit reward texts', $strip);
        $this->assertStringContainsString('href="'.route('settings.rewards').'"', $strip);
        $this->assertStringNotContainsString('btn-primary', $strip);
    }

    public function test_qf4_truth_strip_unset_state(): void
    {
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);
        $html = $this->qrHtml($business);

        $strip = $this->between($html, 'id="poster-says"', 'id="code-on-its-own"');
        $this->assertStringContainsString('What your poster says', $strip);
        $this->assertStringContainsString(
            'Your poster has no reward text yet. Add one so customers know what they get.',
            $strip
        );
        $this->assertStringContainsString('Edit reward texts', $strip);
        $this->assertStringContainsString('href="'.route('settings.rewards').'"', $strip);
        $this->assertDoesNotMatchRegularExpression('/data-poster-friend-text>/', $strip);
    }

    public function test_qf4_truth_strip_without_referrer_shows_friend_only(): void
    {
        // Re-derived under ONE-REWARD C-2 (two-key review): the
        // World default business is SPLIT, so a null referrer
        // column alone is now the SPLIT + blank state, where
        // the panel renders the resolver's fallback text as
        // the referrer line (pinned in OneRewardSignupTest).
        // "Friend only" is the UNIFIED state, so this fixture
        // names it explicitly with the flag off.
        $business = World::business(['referrer_reward_text' => null, 'reward_split' => false]);
        $html = $this->qrHtml($business);

        $strip = $this->between($html, 'id="poster-says"', 'id="code-on-its-own"');
        $this->assertSame(1, preg_match('/data-poster-friend-text>([^<]+)</', $strip, $m), 'QF-4: the strip friend element is missing.');
        $this->assertSame($business->friend_reward_text, html_entity_decode($m[1]));
        $this->assertDoesNotMatchRegularExpression('/data-poster-referrer-text>/', $strip);
    }

    public function test_qf5_redemption_is_a_numbered_three_step_list(): void
    {
        $html = $this->qrHtml(World::business());

        $panel = $this->between($html, 'id="how-redemption-works"', 'id="poster-says"');
        $this->assertStringContainsString('How redemption works', $panel);
        $this->assertStringContainsString('<ol', $panel);
        $this->assertSame(3, substr_count($panel, '<li'), 'QF-5: exactly three steps.');

        $text = html_entity_decode((string) preg_replace('/\s+/', ' ', strip_tags($panel)));
        // [QF-2] verbatim, as a sequence: the three numbered
        // steps plus the closing line, nothing from the shipped
        // paragraph lost (customer shows code; staff scans with
        // their own phone; tap Mark as Redeemed; no app, no
        // login, nothing to type).
        $this->assertStringContainsString('1. A customer shows their reward code.', $text);
        $this->assertStringContainsString('2. A staff member scans it with their own phone.', $text);
        $this->assertStringContainsString('3. They tap Mark as Redeemed.', $text);
        $this->assertStringContainsString('No app, no login, nothing to type.', $text);
        // The compressed paragraph form is gone.
        $this->assertStringNotContainsString('Any staff member scans it with their own phone and taps', $html);
    }

    public function test_qf6_final_page_order_and_danger_last(): void
    {
        $html = $this->qrHtml(World::business());

        $order = [
            'Your QR code',
            'id="start-here"',
            'Share your code online',
            'id="by-spot"',
            'id="also-available"',
            'id="how-redemption-works"',
            'id="poster-says"',
            'id="code-on-its-own"',
            'card-danger',
        ];
        $positions = [];
        foreach ($order as $marker) {
            $pos = strpos($html, $marker);
            $this->assertNotFalse($pos, "QF-6: marker missing: {$marker}");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'QF-6: page order must match Variant A.');

        // The truth strip sits directly above the utility panel:
        // nothing but the strip itself between the two markers'
        // panels (asserted by the between() span holding only the
        // strip's own content in test_qf4) and the danger zone is
        // the last panel: its modal copy is untouched.
        $danger = substr($html, strpos($html, 'card-danger'));
        $this->assertStringContainsString('Replace all codes', $danger);
        $this->assertStringContainsString('Replace all codes?', $danger);
        $this->assertStringContainsString(
            'Every printed code for your store will stop working right away. Print new posters for each spot after you replace them.',
            $danger
        );
    }

    public function test_qf6_inactive_gate_sits_between_lede_and_poster_section(): void
    {
        // Flow off. RE-DERIVED (activation redesign,
        // 2026-10-30): the own-nocard shape this pin used
        // (everything but the card) is LIVE under the
        // allowance gate — it is the factory's own-nocard
        // world, whose guarantee inverts with this build. The
        // gate-position pin uses the same shape PLUS the
        // allowance spent: five counted visits, no card (the
        // notice below is the ACT-QR-02 allowance form).
        $business = World::business(['billing_status' => 'none']);
        $qfReferrer = World::customer('+12125550801', 'Reg', 'Ular');
        [$qfMembership] = World::membership($business, $qfReferrer);
        for ($i = 0; $i < 5; $i++) {
            $qfFriend = World::customer('+1212555081'.(string) $i, 'Fri', 'End');
            [$qfReferral] = World::referral($qfMembership, $qfFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $qfReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
        $html = $this->qrHtml($business->fresh());

        $this->assertFalse($business->flowIsActive());
        $lede = strpos($html, 'Customers scan this code to join and start referring.');
        // The gate marker is the notice's own first words in
        // the form this state renders: ACT-QR-02's "Your code
        // is paused." (the 'This code is not active yet.'
        // heading belongs to the rewards/account forms).
        $gate = strpos($html, 'Your code is paused.');
        $start = strpos($html, 'id="start-here"');
        $this->assertNotFalse($lede);
        $this->assertNotFalse($gate, 'QF-6: the inactive gate renders while the flow is off.');
        $this->assertNotFalse($start);
        $this->assertLessThan($gate, $lede);
        $this->assertLessThan($start, $gate);

        // Flow on: no gate anywhere.
        $active = $this->qrHtml(World::business());
        $this->assertStringNotContainsString('This code is not active yet.', $active);
    }
}
