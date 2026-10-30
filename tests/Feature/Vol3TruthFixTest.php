<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * VOL-3 truth fixes (vol3-friction-audit RC-6 + RC-3).
 *
 * RC-6 (STATE-BLIND COPY): inside the 60-second resend cooldown
 * nothing is sent, but the verify screen used to flash "We sent
 * you a new code." — a false statement in that state. The truth
 * in the cooldown state is a wait: the resend button renders
 * disabled with the real remaining wait stated (the
 * auth/verify-email.blade.php disabled-with-wait pattern), and a
 * resend POST that still lands inside the cooldown (a stale
 * render, a second tab) flashes the same wait sentence with the
 * REAL remaining seconds from the server's own last_start_at
 * value, never the sent claim. After the cooldown the resend
 * sends and the sent claim renders — and only then.
 *
 * RC-3: the verify screen announced "Step 2 of 3" on the friend
 * claim path, which has no Step 1 (the friend landing carried no
 * step label) and no Step 3 (the visit page is the completion,
 * showing a Ready pill, not a step). The friend path genuinely
 * has 2 steps — claim form, then code — so it is labelled
 * 1 of 2 / 2 of 2, progress segments included. The enrollment
 * path's 1-2-3 (landing, code, share) is real and unchanged.
 *
 * Pins are exact rendered lines (D-1 lesson: the exact element
 * string) plus absence pins for the false lines, never
 * substrings alone. Time is frozen so the remaining-seconds
 * values are exact, not approximate.
 */
class Vol3TruthFixTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carry($response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    private function beginEnrollment(Business $business, string $qrToken): void
    {
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
    }

    /**
     * Start a friend claim and land on the verify screen. The
     * referrer's membership is aged past the 25-minute gap so a
     * completed claim reaches the visit page.
     *
     * @return array{0: Business, 1: string} business + share token
     */
    private function beginClaim(): array
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $this->carry($this->get(route('refer.friend', ['token' => $shareToken])));
        $this->carry($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        return [$business, $shareToken];
    }

    // ---------- RC-6: the cooldown resend tells the truth ----------

    public function test_verify_screen_inside_the_cooldown_disables_resend_and_states_the_real_wait(): void
    {
        $this->freezeTime();
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);
        $this->assertCount(1, $this->verify->starts);

        // The code was just sent, so the full 60 seconds remain:
        // the button is disabled and the wait is stated exactly.
        $html = $this->get(route('refer.verify'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<button type="submit" class="btn btn-ghost btn-sm" disabled>Resend code<\/button>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<p class="type-caption mt-2 text-text-2">Please wait 60 seconds before asking for a new code\.<\/p>/',
            $html
        );
        $this->assertStringNotContainsString('We sent you a new code', $html);
    }

    public function test_resend_tap_inside_the_cooldown_sends_nothing_and_flashes_the_wait_not_a_send(): void
    {
        $this->freezeTime();
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);

        $this->post(route('refer.verify.resend'))
            ->assertRedirect(route('refer.verify'))
            ->assertSessionHas('verify_notice', 'Please wait 60 seconds before asking for a new code.');
        $this->assertCount(1, $this->verify->starts);

        $html = $this->get(route('refer.verify'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<p class="status status-success mt-3" role="status">Please wait 60 seconds before asking for a new code\.<\/p>/',
            $html
        );
        $this->assertStringNotContainsString('We sent you a new code', $html);
    }

    public function test_resend_wait_counts_down_with_the_real_remaining_seconds(): void
    {
        $this->freezeTime();
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);

        $this->travel(15)->seconds();
        $this->post(route('refer.verify.resend'))
            ->assertSessionHas('verify_notice', 'Please wait 45 seconds before asking for a new code.');
        $this->assertCount(1, $this->verify->starts);

        // The last second of the cooldown reads in the singular.
        $this->travel(44)->seconds();
        $this->post(route('refer.verify.resend'))
            ->assertSessionHas('verify_notice', 'Please wait 1 second before asking for a new code.');
        $this->assertCount(1, $this->verify->starts);
    }

    public function test_resend_after_the_cooldown_sends_and_only_then_claims_a_new_code(): void
    {
        $this->freezeTime();
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->beginEnrollment($business, $qrToken);

        $this->travel(61)->seconds();
        $this->post(route('refer.verify.resend'))
            ->assertRedirect(route('refer.verify'))
            ->assertSessionHas('verify_notice', 'We sent you a new code. It can take a minute to arrive.');
        $this->assertCount(2, $this->verify->starts);

        $html = $this->get(route('refer.verify'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<p class="status status-success mt-3" role="status">We sent you a new code\. It can take a minute to arrive\.<\/p>/',
            $html
        );
        $this->assertStringNotContainsString('before asking for a new code', $html);

        // The fresh code starts a fresh check budget, as before.
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));
    }

    public function test_friend_claim_resend_inside_the_cooldown_also_tells_the_truth(): void
    {
        $this->freezeTime();
        $this->beginClaim();
        $this->assertCount(1, $this->verify->starts);

        $this->post(route('refer.verify.resend'))
            ->assertRedirect(route('refer.verify'))
            ->assertSessionHas('verify_notice', 'Please wait 60 seconds before asking for a new code.');
        $this->assertCount(1, $this->verify->starts);

        $this->get(route('refer.verify'))
            ->assertOk()
            ->assertDontSee('We sent you a new code');
    }

    // ---------- RC-3: the step labels are true on every path ----------

    public function test_enrollment_step_labels_stay_one_two_three_of_three(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $landing = $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<span class="type-caption text-text-2">Step 1 of 3<\/span>/',
            $landing
        );
        $this->assertSame(3, substr_count($landing, 'class="segment'));

        $this->beginEnrollment($business, $qrToken);
        $verify = $this->get(route('refer.verify'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<span class="type-caption text-text-2">Step 2 of 3<\/span>/',
            $verify
        );
        $this->assertStringNotContainsString('Step 2 of 2', $verify);
        $this->assertSame(3, substr_count($verify, 'class="segment'));

        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));
        $share = $this->get(route('refer.share'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<span class="type-caption text-text-2">Step 3 of 3<\/span>/',
            $share
        );
    }

    public function test_friend_step_labels_are_one_and_two_of_two_and_the_visit_page_has_no_step(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        // Step 1 of 2: the friend landing itself.
        $friend = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<span class="type-caption text-text-2">Step 1 of 2<\/span>/',
            $friend
        );
        $this->assertStringNotContainsString('Step 1 of 3', $friend);
        $this->assertSame(2, substr_count($friend, 'class="segment'));

        $this->carry($this->get(route('refer.friend', ['token' => $shareToken])));
        $this->carry($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        // Step 2 of 2: the shared verify screen, branched by kind.
        $verify = $this->get(route('refer.verify'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<span class="type-caption text-text-2">Step 2 of 2<\/span>/',
            $verify
        );
        $this->assertStringNotContainsString('Step 2 of 3', $verify);
        $this->assertSame(2, substr_count($verify, 'class="segment'));

        // The visit page is the completion, not a step: no step
        // label of any count renders there.
        $redeem = $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();
        $visit = $this->get($redeem->headers->get('Location'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Step 3 of 3', $visit);
        $this->assertStringNotContainsString('Step 3 of 2', $visit);
        $this->assertStringNotContainsString('Step 2 of 3', $visit);
        $this->assertStringContainsString('Ready', $visit);
    }
}
