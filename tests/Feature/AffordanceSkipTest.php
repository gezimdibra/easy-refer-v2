<?php

namespace Tests\Feature;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Skip affordance pins (owner findings batch 2, 2026-10-29;
 * TEXT-THAT-IS-AN-ACTION class). Decision: HYBRID, benchmarked in
 * the train report. Every onboarding skip renders as the shared
 * .text-action (link color, semibold, underlined, 44px target),
 * EXCEPT the Card step, whose skip carries a consequence (the code
 * stays off) and renders as a full secondary button. The header
 * exit and the welcome exit carry the same .text-action.
 */
class AffordanceSkipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
        $this->app->instance(SquareCustomerGateway::class, new FakeSquareCustomerGateway);
        config([
            'services.square.application_id' => 'sq0idp-test-app',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
    }

    private function freshOwner(): \App\Models\User
    {
        $business = World::business([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
            'email_verified_at' => null,
        ]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => null])->save();

        return $owner;
    }

    private function assertTextAction(string $html, string $copy): void
    {
        $this->assertSame(1, preg_match('/<a class="text-action[^"]*"[^>]*>'.preg_quote($copy, '/').'<\/a>/', $html),
            "Skip [{$copy}] is not rendered as a .text-action");
    }

    public function test_every_step_skip_carries_the_shared_text_action(): void
    {
        $owner = $this->freshOwner();

        $welcome = $this->actingAs($owner)->get(route('onboarding.welcome'))->assertOk()->getContent();
        $this->assertTextAction($welcome, 'Not now');
        $this->assertTextAction($welcome, 'Skip setup for now');

        $rewards = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'))->assertOk()->getContent();
        $this->assertTextAction($rewards, 'Skip this step');
        $this->assertTextAction($rewards, 'Skip setup for now');

        $verify = $this->actingAs($owner)->get(route('onboarding.step', 'verify'))->assertOk()->getContent();
        $this->assertTextAction($verify, 'Skip for now');

        $poster = $this->actingAs($owner)->get(route('onboarding.step', 'poster'))->assertOk()->getContent();
        $this->assertTextAction($poster, 'Skip this step');

        $test = $this->actingAs($owner)->get(route('onboarding.step', 'test'))->assertOk()->getContent();
        $this->assertTextAction($test, 'Skip the test');
    }

    public function test_try_it_fallback_url_is_a_tappable_link_not_plain_text(): void
    {
        // Timing-lane bonus finding (2026-10-29): on the owner's
        // phone the Try-it step's fallback join URL rendered as
        // plain text, forcing a ~37-character retype. On a screen
        // surface the fallback IS the action, so it carries the
        // .text-action treatment and a real href. (The poster and
        // receipt-card instances of the same sentence are print
        // surfaces and correctly stay plain text.)
        $owner = $this->freshOwner();

        $test = $this->actingAs($owner)->get(route('onboarding.step', 'test'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<a class="text-action" href="[^"]*\/join\/[^"]+"[^>]*>[^<]+<\/a>/', $test),
            'Try-it fallback URL is not rendered as a .text-action link');
    }

    public function test_qr_inactive_notice_actions_are_text_actions_not_bare_text(): void
    {
        // TEXT-THAT-IS-AN-ACTION instance found by this train's
        // cold-reader pass: the shared qr-inactive notice renders
        // on the Poster and Try-it steps (and the QR / poster
        // pages), and its resolving actions ("Set your rewards" /
        // "Add a card") are the primary path out of the blocking
        // state. They were bare styled text; they now carry the
        // shared .text-action. Copy unchanged.
        $owner = $this->freshOwner();

        foreach (['poster', 'test'] as $step) {
            $html = $this->actingAs($owner)->get(route('onboarding.step', $step))->assertOk()->getContent();
            $this->assertSame(1, preg_match('/<a class="text-action"[^>]*>Set your rewards<\/a>/', $html),
                "Step {$step}: the notice action is not a .text-action");
            $this->assertSame(0, preg_match('/<a class="font-semibold text-link hover:underline"[^>]*>Set your rewards<\/a>/', $html),
                "Step {$step}: the notice action still uses the bare classes");
        }
    }

    public function test_card_skip_is_a_full_secondary_button_because_it_carries_a_consequence(): void
    {
        $owner = $this->freshOwner();

        $card = $this->actingAs($owner)->get(route('onboarding.step', 'card'))->assertOk()->getContent();
        // RE-DERIVED (activation redesign, census C-08,
        // ACT-CARD-06): the label is "Skip for now" — the code
        // is NOT off without a card — and it stays a full
        // secondary button for the same reason this pin
        // exists: it carries a consequence (the code pauses
        // when the free visits end), named in the line under it.
        $this->assertSame(1, preg_match('/<a class="btn btn-secondary"[^>]*>Skip for now<\/a>/', $card));
        $this->assertSame(0, preg_match('/<a class="text-action[^"]*"[^>]*>Skip for now<\/a>/', $card));
        $this->assertStringContainsString('Your code works for your first 5 visits with no card.', $card);
        // Its explanatory line stays plain text, not an action.
        $this->assertStringContainsString('You can add a card any time in Settings', $card);
    }

    public function test_no_step_skip_uses_the_old_bare_link_classes(): void
    {
        $owner = $this->freshOwner();
        foreach (['rewards', 'verify', 'poster', 'test'] as $step) {
            $html = $this->actingAs($owner)->get(route('onboarding.step', $step))->assertOk()->getContent();
            $this->assertSame(0, preg_match('/<a class="font-semibold text-link hover:underline"[^>]*>Skip/', $html),
                "Step {$step} still renders its skip with the old bare classes");
        }
        // The one surviving old-style link on the verify step is
        // in-copy navigation (Settings -> Account inside a
        // sentence), which stays a link and is not a finding.
        $verify = $this->actingAs($owner)->get(route('onboarding.step', 'verify'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($verify, 'font-semibold text-link hover:underline'));
    }
}
