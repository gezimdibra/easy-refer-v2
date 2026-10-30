<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The welcome email under the revised lifecycle (owner
 * amendment, 2026-10-29; Amendment 59): registration sends
 * exactly ONE email, the verification notification, and no
 * WelcomeMail at all. WelcomeMail is the unverified nudge,
 * sent once at 24+ hours by WelcomeNudgeService (pinned in
 * WelcomeNudgeTest). Verification resends send the verification
 * notification only. The render pins below stand: they pin the
 * mailable's render-time truth in every state its nudge render
 * can meet, including the verify-between-read-and-render race.
 */
class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same breach-corpus stub as RegistrationTest: signup
        // password screening must not leave the test sandbox.
        Http::fake(fn () => Http::response('', 200));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Corner Deli',
            'name' => 'Dana Owner',
            'email' => 'dana@example.test',
            'phone' => '(212) 555-0147',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ], $overrides);
    }

    public function test_registration_sends_exactly_one_email_the_verification_notification_and_no_welcome(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post(route('register'), $this->payload())->assertRedirect(route('onboarding.welcome'));

        // Exactly one email leaves at registration, and it is
        // the verification notification (the Registered event's
        // framework listener -> User::sendEmailVerificationNotification).
        $user = User::firstOrFail();
        Notification::assertSentToTimes($user, \App\Notifications\VerifyEmailNotification::class, 1);
        Notification::assertCount(1);

        // Absence pins, both directions: no welcome mail is
        // queued or sent at the signup moment, in any form.
        Mail::assertNotQueued(WelcomeMail::class);
        Mail::assertNotSent(WelcomeMail::class);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertNull($user->welcome_nudge_sent_at);
    }

    public function test_verification_resend_sends_the_verification_email_and_never_a_welcome(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post(route('register'), $this->payload())->assertRedirect(route('onboarding.welcome'));
        Mail::assertNotQueued(WelcomeMail::class);

        // The resend converges on the one flavor for this
        // moment: the verification notification again.
        $this->post(route('verification.send'))->assertRedirect();
        Notification::assertSentToTimes(User::firstOrFail(), \App\Notifications\VerifyEmailNotification::class, 2);
        Notification::assertCount(2);

        Mail::assertNotQueued(WelcomeMail::class);
        Mail::assertNotSent(WelcomeMail::class);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_duplicate_registration_attempt_sends_no_second_email_of_any_kind(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post(route('register'), $this->payload())->assertRedirect(route('onboarding.welcome'));
        $this->post(route('logout'));

        $this->post(route('register'), $this->payload())
            ->assertSessionHasErrors('account');

        Notification::assertCount(1);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_factory_created_accounts_queue_no_welcome(): void
    {
        Mail::fake();

        // The seeded and demo account path: models created
        // directly never pass through the registration flow.
        User::factory()->create();

        Mail::assertNothingQueued();
    }

    public function test_welcome_subject_and_unverified_render_carry_the_required_elements(): void
    {
        $user = User::factory()->unverified()->create();

        $mail = new WelcomeMail($user);

        // CHANGED PIN (TS-PM-01, truth sweep 2026-10-29 PM;
        // supersedes the TS3-06 re-derivation in part): a fresh
        // unverified account has two ACTIVATION steps
        // outstanding (card, friend reward text); verification
        // gates nothing and never counts as a step before the
        // code works, so the subject counts two, not three.
        //
        // RE-DERIVED (activation redesign, census C-28,
        // 2026-10-30): the card counts as a step left only
        // when the business needs it to keep its code on (no
        // card AND the free allowance spent). A fresh account's
        // allowance is untouched, so the one step before its
        // code works is the reward; the subject counts one.
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            $mail->envelope()->subject,
        );

        $html = $mail->render();

        // Required: the greeting, what they joined, the one next
        // action with the repeated verification link, and where
        // the dashboard and help live.
        $this->assertStringContainsString('Welcome to Easy Refer', $html);
        // The name is a faker draw; Blade escapes it in the HTML,
        // so assert the escaped form (an apostrophe name like
        // O'Kon-Thiel otherwise fails this test at random).
        $this->assertStringContainsString(e($user->business->name), $html);
        $this->assertStringContainsString('Pay Per Visit', $html);
        $this->assertStringContainsString('you pay $2 only when a referred friend actually visits your store and redeems', $html);
        $this->assertStringContainsString('first 5 visits are free', $html);
        // Amendment 12 (release 11) jargon sweep: the word is
        // gone from the rendered email entirely.
        $this->assertStringNotContainsString('verified', $html);
        $this->assertStringContainsString('never pay more than $99 in a month in visit charges', $html);
        $this->assertStringContainsString('Nothing is charged today', $html);
        // CHANGED PIN (TS-PM-01): was "3 steps left before your
        // code works: verify this email address". The count is
        // the two activation legs and names the first of them;
        // verification is a separate sentence with its reason.
        $this->assertStringContainsString('One step left before your code works: set your rewards', $html);
        $this->assertStringContainsString('Verify this email address so your receipts and password resets reach you.', $html);
        $this->assertStringNotContainsString('before your code works: verify', $html);
        $this->assertStringContainsString('Verify your email address', $html);
        $this->assertStringContainsString('verify-email', $html);
        $this->assertStringContainsString('signature=', $html);
        $this->assertStringContainsString(route('dashboard'), $html);
        $this->assertStringContainsString(route('faq'), $html);
        $this->assertStringContainsString('support@easy-refer.com', $html);

        $this->assertBannedContentAbsent($html);
    }

    public function test_verified_owner_render_points_at_the_qr_page_instead(): void
    {
        $user = User::factory()->create();
        // Amendment 40 (F2-WEL-01): the QR-pointing branch is
        // verified + card + friend reward set; the factory
        // business carries no reward text, so state it — the
        // textless form is pinned in MissingRewardTextGateTest.
        $user->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $this->assertTrue($user->hasVerifiedEmail());
        // Train A2 (F-10): the QR-pointing branch is verified
        // AND card on file; a verified owner without a card
        // truthfully gets the add-your-card step instead.
        \App\Models\PaymentMethod::create([
            'business_id' => $user->business->id,
            'square_card_id' => 'card_welcome',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
        ]);

        $html = (new WelcomeMail($user))->render();

        $this->assertStringContainsString('Your email address is confirmed', $html);
        $this->assertStringNotContainsString('verified', $html);
        $this->assertStringContainsString('Get your QR code', $html);
        $this->assertStringContainsString(route('owner.qr'), $html);
        $this->assertStringNotContainsString('Verify your email address', $html);
        $this->assertStringNotContainsString('verify-email', $html);
        $this->assertStringNotContainsString('signature=', $html);

        $this->assertBannedContentAbsent($html);
    }

    // NEW PIN (TS-PM-01): the verified variants keep their
    // counts under the new premise, because verification never
    // counted for them: verified with only the card done is
    // one activation step (the reward); verified with only
    // the reward done is one (the card); subject, title, and
    // body agree in each.
    public function test_verified_variants_counts_stay_exact_under_the_new_premise(): void
    {
        // Verified, card only: one step, the rewards.
        $cardOnly = User::factory()->create();
        \App\Models\PaymentMethod::create([
            'business_id' => $cardOnly->business->id,
            'square_card_id' => 'card_welcome_v1',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
        ]);
        $mail = new WelcomeMail($cardOnly->fresh());
        $this->assertSame('Welcome to Easy Refer: one step left before your code works', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertSame(1, preg_match('/<title>(.*?)<\/title>/', $html, $m));
        $this->assertSame($mail->envelope()->subject, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $this->assertStringContainsString('Your email address is confirmed. One step left before your code works: set your rewards.', $html);

        // Verified, rewards only: RE-DERIVED (activation
        // redesign, census C-28/C-29): with the allowance
        // untouched this owner's code already works, so the
        // count is ZERO and the confirmed branch says the code
        // is ready. (The card step returns only once the
        // allowance is spent; ActivationAllowanceTest P10 pins
        // that state.)
        $rewardsOnly = User::factory()->create();
        $rewardsOnly->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $mail2 = new WelcomeMail($rewardsOnly->fresh());
        $this->assertSame('Welcome to Easy Refer', $mail2->envelope()->subject);
        $this->assertStringContainsString('Your email address is confirmed, so your code is ready to go out.', $mail2->render());
    }

    // NEW PINS (TS-PM-02): the trailing reward line carries no
    // "two rewards" form in any branch. Unified reads the one
    // reward; split reads both rewards named (the poster
    // system's branching); a set reward is stated as set, an
    // unset reward is the instruction.
    public function test_welcome_trailing_reward_line_branches_unified_and_split(): void
    {
        // Unified, reward missing (unverified render): the
        // one-reward instruction.
        $unifiedMissing = User::factory()->unverified()->create();
        $html = (new WelcomeMail($unifiedMissing))->render();
        $this->assertStringContainsString('Set your reward, then print your poster and tent card', $html);
        $this->assertStringNotContainsString('two rewards', $html);

        // Unified, reward set (verified + card, ready branch):
        // stated as set, never an instruction to set it again.
        $unifiedSet = User::factory()->create();
        $unifiedSet->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        \App\Models\PaymentMethod::create([
            'business_id' => $unifiedSet->business->id,
            'square_card_id' => 'card_welcome_u2',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
        ]);
        $html = (new WelcomeMail($unifiedSet->fresh()))->render();
        $this->assertStringContainsString('Your reward is set. Then print your poster and tent card', $html);
        $this->assertStringNotContainsString('two rewards', $html);
        $this->assertStringNotContainsString('Set your reward, then print', $html);

        // Split, reward set (verified + card, ready branch):
        // both rewards named, stated as set.
        $splitSet = User::factory()->create();
        $splitSet->business->forceFill([
            'friend_reward_text' => 'Free coffee',
            'reward_split' => true,
            'referrer_reward_text' => 'Free tea',
        ])->save();
        \App\Models\PaymentMethod::create([
            'business_id' => $splitSet->business->id,
            'square_card_id' => 'card_welcome_s2',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
        ]);
        $html = (new WelcomeMail($splitSet->fresh()))->render();
        $this->assertStringContainsString('Your friend reward and your referrer reward are set. Then print your poster and tent card', $html);
        $this->assertStringNotContainsString('two rewards', $html);

        // Split, reward missing (unverified render): both
        // rewards named in the instruction.
        $splitMissing = User::factory()->unverified()->create();
        $splitMissing->business->forceFill([
            'friend_reward_text' => null,
            'reward_split' => true,
            'referrer_reward_text' => 'Free tea',
        ])->save();
        $html = (new WelcomeMail($splitMissing->fresh()))->render();
        $this->assertStringContainsString('Set your friend reward and your referrer reward, then print your poster and tent card', $html);
        $this->assertStringNotContainsString('two rewards', $html);
    }

    private function assertBannedContentAbsent(string $html): void
    {
        // Retired Pine values (plain forms) and the retired
        // cobalt, from the Lane C final-color sweep set.
        foreach (['#0b6142', '#095139', '#07402d', '#127a54', '#cdebdb', '#edf7f1', '#2563eb'] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
            $this->assertStringNotContainsString(strtoupper($retired), $html);
        }

        $this->assertStringNotContainsString('—', $html);
        $this->assertStringNotContainsString('Lorem', $html);
        $this->assertStringNotContainsString('{verification_link}', $html);
        $this->assertStringNotContainsString('TODO', $html);
    }
}
