<?php

namespace Tests\Feature\Flow;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RS-N3 (Train B, Amendment 39): the honest "no catch" strip at
 * the phone field on the friend landing and the enrollment
 * landing. The design pass (train-b-design-pass.md Section 4)
 * FAILED the backlog's draft wording on paper: the business does
 * not pay "only if you visit" (it pays when a visit counts, and
 * the first five counted visits and every visit past the cap are
 * free), and texts are not "only about this reward" (the one
 * consent also covers the business's campaign texts). The strip
 * that ships makes three checkable claims instead, worded
 * exactly as the pass's redesign, and asserts no fee amount.
 */
class NoCatchStripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function stripOf(string $html): string
    {
        $start = strpos($html, 'data-no-catch-strip');
        $this->assertNotFalse($start, 'the strip renders');
        $end = strpos($html, '</div>', $start);

        // The strip is one well holding three rows; take a wide
        // window and let the row assertions work inside it.
        return substr($html, $start, 3000);
    }

    private function friendPage(Business $business): string
    {
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        return $this->get(route('refer.friend', ['token' => $shareToken]))
            ->assertOk()
            ->getContent();
    }

    private function landingPage(Business $business): string
    {
        [, $qrToken] = World::qr($business);

        return $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertOk()
            ->getContent();
    }

    public function test_friend_landing_renders_the_three_rows_verbatim_under_the_phone_field(): void
    {
        $business = World::business();
        $html = $this->friendPage($business);
        $strip = $this->stripOf($html);

        $this->assertStringContainsString('You pay nothing to get or use this reward. '.$business->name.' pays for the program.', $strip);
        $this->assertStringContainsString('Your number is never sold.', $strip);
        $this->assertStringContainsString('We text you about your rewards. '.$business->name.' may also text you offers. Reply STOP to stop the texts.', $strip);

        // DOM order: phone field, strip, consent checkbox.
        $phone = strpos($html, 'id="phone"');
        $stripPos = strpos($html, 'data-no-catch-strip');
        $consent = strpos($html, 'id="consent"');
        $this->assertTrue($phone < $stripPos && $stripPos < $consent, 'strip sits between the phone field and the consent');
    }

    public function test_enrollment_landing_renders_the_joining_frame_and_the_same_other_rows(): void
    {
        $business = World::business();
        $html = $this->landingPage($business);
        $strip = $this->stripOf($html);

        $this->assertStringContainsString('Joining is free. You pay nothing for this program. '.$business->name.' pays for it.', $strip);
        $this->assertStringContainsString('Your number is never sold.', $strip);
        $this->assertStringContainsString('We text you about your rewards. '.$business->name.' may also text you offers. Reply STOP to stop the texts.', $strip);
        $this->assertStringNotContainsString('You pay nothing to get or use this reward.', $strip);

        $phone = strpos($html, 'id="phone"');
        $stripPos = strpos($html, 'data-no-catch-strip');
        $consent = strpos($html, 'id="consent"');
        $this->assertTrue($phone < $stripPos && $stripPos < $consent, 'strip sits between the phone field and the consent');
    }

    public function test_the_strip_carries_none_of_the_rejected_or_invented_claims(): void
    {
        $business = World::business();

        foreach ([$this->friendPage($business), $this->landingPage($business)] as $html) {
            $strip = $this->stripOf($html);
            $this->assertStringNotContainsString('only if you visit', $strip);
            $this->assertStringNotContainsString('only about this reward', $strip);
            $this->assertStringNotContainsString('free forever', $strip);
            $this->assertStringNotContainsString('$2', $strip);
            $this->assertStringNotContainsString('2 dollars', $strip);
            $this->assertStringNotContainsString("\u{2014}", $strip, 'no em dash in the strip');
            // The strip never restates either reward (the lines
            // above it own the rewards).
            $this->assertStringNotContainsString('$10 off', $strip);
        }
    }

    public function test_friend_page_composition_train_a_line_then_strip_each_exactly_once(): void
    {
        $business = World::business();
        $html = $this->friendPage($business);

        $f1 = 'Reg gets $10 off when you visit.';
        $this->assertSame(1, substr_count($html, $f1), 'the Train A both-rewards line renders exactly once');
        $this->assertSame(1, substr_count($html, 'data-no-catch-strip'), 'the strip renders exactly once');
        $this->assertTrue(strpos($html, $f1) < strpos($html, 'data-no-catch-strip'), 'the F-1 line stands above the strip');
    }

    public function test_the_strip_is_identical_on_free_visits_and_with_none_left(): void
    {
        // The wording makes no per-visit claim, so no state branch
        // may exist: a business still on its free visits and one
        // past them render the same strip.
        $fresh = World::business(['name' => 'Same Name Cafe', 'free_visits_remaining' => 5]);
        $spent = World::business(['name' => 'Same Name Cafe', 'free_visits_remaining' => 0]);

        $this->assertSame(
            $this->stripOf($this->friendPage($fresh)),
            $this->stripOf($this->friendPage($spent)),
        );
    }
}
