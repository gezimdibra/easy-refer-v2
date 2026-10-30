<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeMail;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The unverified welcome nudge (owner amendment, 2026-10-29;
 * Amendment 59): WelcomeMail fires exactly once per account
 * when the account is at least 24 hours old AND the email is
 * still unverified. Verified inside day one, it never exists.
 * The nudge's steps-left count is recomputed at send time from
 * SetupChecklist (the onboarding steps' own predicates), and
 * its verify link is a fresh signed link minted at send time.
 * All mail is faked; nothing real is ever sent.
 *
 * TS-PM-01 re-pin (truth sweep 2026-10-29 PM): the count names
 * ACTIVATION blockers only (card, reward). Verification is the
 * mail's own ask, carried with its true reason, and never
 * counts as a step before the code works: a card plus reward
 * owner who never verifies is at zero steps, with the
 * verification ask still in the mail.
 */
class WelcomeNudgeTest extends TestCase
{
    use RefreshDatabase;

    private function agedUnverified(int $hours): User
    {
        $user = User::factory()->unverified()->create();
        $user->forceFill(['created_at' => now()->subHours($hours)])->save();

        return $user->fresh();
    }

    private function addCard(User $user): void
    {
        PaymentMethod::create([
            'business_id' => $user->business->id,
            'square_card_id' => 'card_nudge',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);
    }

    /** Capture the one sent WelcomeMail for render assertions. */
    private function sentWelcome(): WelcomeMail
    {
        $captured = null;
        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail) use (&$captured) {
            $captured = $mail;

            return true;
        });
        $this->assertNotNull($captured);

        return $captured;
    }

    public function test_a_23_hour_old_unverified_account_gets_nothing(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(23);

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertNull($user->fresh()->welcome_nudge_sent_at);
    }

    public function test_a_25_hour_old_unverified_account_gets_exactly_one_nudge_and_never_a_second(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(25);

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        Mail::assertSent(WelcomeMail::class, 1);
        Mail::assertSent(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo($user->email));
        $this->assertNotNull($user->fresh()->welcome_nudge_sent_at);

        // Hour 49 (and every later run): the marker holds, no
        // second nudge ever.
        $user->forceFill(['created_at' => now()->subHours(49)])->save();
        $this->artisan('accounts:welcome-nudge')->assertSuccessful();
        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        Mail::assertSent(WelcomeMail::class, 1);
        Mail::assertSentCount(1);
    }

    public function test_an_account_exactly_24_hours_old_is_due(): void
    {
        Mail::fake();
        $this->agedUnverified(24);

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        Mail::assertSent(WelcomeMail::class, 1);
    }

    public function test_an_account_verified_at_hour_two_gets_nothing_at_hour_25(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(25);
        // Verified two hours after signup, 23 hours ago.
        $user->forceFill(['email_verified_at' => now()->subHours(23)])->save();

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($user->fresh()->welcome_nudge_sent_at);
    }

    // CHANGED PIN (TS-PM-01): was "count is three for a
    // did-nothing account" (verify + card + reward). The
    // activation count is two: card and reward only.
    //
    // RE-DERIVED (activation redesign, census C-28,
    // 2026-10-30) and RENAMED: the card is a step left only
    // when the allowance is spent, and a did-nothing account's
    // allowance is full, so the count is ONE (the reward).
    public function test_the_nudge_count_is_one_for_a_did_nothing_account(): void
    {
        Mail::fake();
        $this->agedUnverified(25);

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        $mail = $this->sentWelcome();
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            $mail->envelope()->subject,
        );
        $html = $mail->render();
        $this->assertSame(1, preg_match('/<title>(.*?)<\/title>/', $html, $m));
        $this->assertSame($mail->envelope()->subject, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString(
            'One step left before your code works: set your rewards.',
            $text,
        );
        // The verification ask stands as its own sentence, with
        // the reason the other surfaces give, outside the count.
        $this->assertStringContainsString(
            'Verify this email address so your receipts and password resets reach you.',
            $text,
        );
        $this->assertStringNotContainsString('3 steps left', $text);
        $this->assertStringNotContainsString('before your code works: verify', $text);
    }

    // CHANGED PIN (TS-PM-01): was "count is one when card and
    // rewards are done" (the one being verification). The
    // activation count is zero; the mail claims no step before
    // the code works and still carries the verification ask.
    public function test_the_nudge_count_is_zero_when_card_and_rewards_are_done(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(25);
        $user->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $this->addCard($user);

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        $mail = $this->sentWelcome();
        $this->assertSame(
            'Welcome to Easy Refer',
            $mail->envelope()->subject,
        );
        $html = $mail->render();
        $this->assertSame(1, preg_match('/<title>(.*?)<\/title>/', $html, $m));
        $this->assertSame($mail->envelope()->subject, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString(
            'No steps left before your code works.',
            $text,
        );
        $this->assertStringContainsString(
            'Verify this email address so your receipts and password resets reach you.',
            $text,
        );
        $this->assertStringContainsString('Verify your email address', $text);
        $this->assertStringNotContainsString('step left before your code works:', $text);
        $this->assertStringNotContainsString('One step left', $text);
        $this->assertStringNotContainsString('2 steps left', $text);
    }

    // CHANGED PIN (TS-PM-01): was "count is two when only the
    // card is done" (verify + reward). The activation count is
    // one: the reward leg only.
    public function test_the_nudge_count_is_one_when_only_the_card_is_done(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(25);
        $this->addCard($user);

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        $mail = $this->sentWelcome();
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            $mail->envelope()->subject,
        );
        $text = preg_replace('/\s+/', ' ', strip_tags($mail->render()));
        $this->assertStringContainsString(
            'One step left before your code works: set your rewards.',
            $text,
        );
        $this->assertStringNotContainsString('2 steps left', $text);
    }

    // NEW PIN (TS-PM-01): rewards-only was the mirror state:
    // one activation blocker, the card leg, named as the step.
    //
    // RE-DERIVED (activation redesign, census C-28,
    // 2026-10-30) and RENAMED: a rewards-done cardless account
    // with its allowance untouched has a WORKING code, so the
    // nudge count is zero and its callout says so. The card
    // step returns only when the allowance is spent
    // (ActivationAllowanceTest P10 pins it).
    public function test_the_nudge_count_is_zero_when_only_rewards_are_done(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(25);
        $user->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        $mail = $this->sentWelcome();
        $this->assertSame(
            'Welcome to Easy Refer',
            $mail->envelope()->subject,
        );
        $text = preg_replace('/\s+/', ' ', strip_tags($mail->render()));
        $this->assertStringContainsString(
            'No steps left before your code works.',
            $text,
        );
        $this->assertStringContainsString(
            'Verify this email address so your receipts and password resets reach you.',
            $text,
        );
        $this->assertStringNotContainsString('2 steps left', $text);
    }

    public function test_the_nudges_verify_link_actually_verifies_the_email(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(25);

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        $html = html_entity_decode($this->sentWelcome()->render(), ENT_QUOTES, 'UTF-8');
        $this->assertSame(1, preg_match('#https?://[^\s"\']+/verify-email/\d+/[a-f0-9]{40}\?[^\s"\']+#', $html, $m));

        $this->actingAs($user)->get($m[0])->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // And once verified, no later run can nudge again, even
        // with the marker somehow cleared: eligibility itself
        // excludes verified addresses.
        $user->forceFill(['welcome_nudge_sent_at' => null])->save();
        Mail::fake();
        $this->artisan('accounts:welcome-nudge')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_a_closed_business_is_not_nudged_and_not_stamped(): void
    {
        Mail::fake();
        $user = $this->agedUnverified(30);
        $user->business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->artisan('accounts:welcome-nudge')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($user->fresh()->welcome_nudge_sent_at);
    }
}
