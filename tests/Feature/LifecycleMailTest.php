<?php

namespace Tests\Feature;

use App\Mail\AllowanceLastVisitMail;
use App\Mail\AllowancePausedMail;
use App\Mail\CardExpiredMail;
use App\Mail\LifecycleFirstScanMail;
use App\Mail\LifecycleSetupPosterMail;
use App\Mail\LifecycleSetupRewardMail;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\LifecycleEmailSend;
use App\Models\PaymentMethod;
use App\Models\ReferralEvent;
use App\Models\User;
use App\Services\Owner\LifecycleMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\World;
use Tests\TestCase;

/**
 * M-5 lifecycle emails acceptance pins (design pass Sections
 * 9.4 and 4.3; copy Amendment 72). Every email is pinned in
 * both directions: it fires in its named state and is silent
 * in every adjacent state. Stale-state probes move the state
 * between the ledger claim and the send (the service's
 * afterClaim hook) and pin that the post-claim re-read
 * releases the claim and sends nothing. All mail is faked;
 * nothing real is ever sent.
 *
 * Time is frozen at 2026-06-15 14:00 UTC, which is 10:00 in
 * the fixture businesses' America/New_York zone, inside the
 * 08:00 to 19:00 business-local send window.
 */
class LifecycleMailTest extends TestCase
{
    use RefreshDatabase;

    private int $visitSequence = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-15 14:00:00', 'UTC'));
        Mail::fake();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function business(array $overrides = [], int $ageDays = 0, int $ageHours = 0): Business
    {
        $business = World::business($overrides);
        $owner = World::owner($business);

        // World::business passes email_verified_at into
        // User::create, where it is not fillable, so a World
        // owner is unverified until it is force-filled here:
        // the fixture default is a verified owner, and the
        // unverified pins below null it back out explicitly.
        $owner->forceFill(['email_verified_at' => now()->subHour()])->save();

        if ($ageDays > 0 || $ageHours > 0) {
            $created = now()->subDays($ageDays)->subHours($ageHours);
            $business->forceFill(['created_at' => $created])->save();
            $owner->forceFill(['created_at' => $created])->save();
        }

        return $business->fresh();
    }

    private function settleVisits(Business $business, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $n = $this->visitSequence++;
            $referrer = World::customer('+1212555'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'Reg', 'Ular');
            [$membership] = World::membership($business, $referrer);
            $friend = World::customer('+1212556'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);

            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
    }

    private function addCard(Business $business, array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'business_id' => $business->id,
            'square_card_id' => 'card_m5_'.$business->id.'_'.uniqid(),
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ], $overrides));
    }

    private function event(Business $business, string $type, ?Carbon $at = null): ReferralEvent
    {
        $event = ReferralEvent::create([
            'business_id' => $business->id,
            'event_type' => $type,
            'actor' => 'owner',
        ]);

        if ($at !== null) {
            $event->forceFill(['created_at' => $at])->save();
        }

        return $event;
    }

    private function ledgerRow(Business $business, string $key, ?Carbon $sentAt = null): LifecycleEmailSend
    {
        return LifecycleEmailSend::create([
            'business_id' => $business->id,
            'user_id' => World::owner($business)->id,
            'send_key' => $key,
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    private function runCommand(): void
    {
        $this->artisan('lifecycle:mail')->assertSuccessful();
    }

    /** Capture the one sent mailable of a class for render assertions. */
    private function sentMail(string $class): Mailable
    {
        $captured = null;
        Mail::assertSent($class, function (Mailable $mail) use (&$captured) {
            $captured = $mail;

            return true;
        });
        $this->assertNotNull($captured);

        return $captured;
    }

    private function renderedText(Mailable $mail): string
    {
        // Visible text only: the shared shell's <style> block
        // (CSS braces and comments) is not copy.
        $html = preg_replace('#<style.*?</style>#s', ' ', $mail->render());

        return preg_replace('/\s+/', ' ', strip_tags($html));
    }

    private function assertCleanCopy(string $text): void
    {
        $this->assertStringNotContainsString('—', $text, 'no em dash in any shipped string');
        $this->assertStringNotContainsString('verified visit', strtolower($text));
        $this->assertStringNotContainsString('{', $text, 'no unfilled placeholder leaks');
        $this->assertStringNotContainsString('}', $text, 'no unfilled placeholder leaks');
    }

    /**
     * Run the service with a probe that moves state between
     * the claim and the send for the named key.
     */
    private function runWithClaimProbe(string $onlyKey, callable $probe): void
    {
        ProbingLifecycleMailService::$probe = function (Business $business, string $key) use ($onlyKey, $probe): void {
            if ($key === $onlyKey) {
                $probe($business);
            }
        };

        try {
            app(ProbingLifecycleMailService::class)->run();
        } finally {
            ProbingLifecycleMailService::$probe = null;
        }
    }

    // ---------------------------------------------------------------
    // D1: reward stall
    // ---------------------------------------------------------------

    public function test_d1_fires_at_72h_not_before_and_only_once(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageHours: 71);

        $this->runCommand();
        Mail::assertNothingSent();

        $created = now()->subHours(73);
        $business->forceFill(['created_at' => $created])->save();
        World::owner($business)->forceFill(['created_at' => $created])->save();

        $this->runCommand();
        Mail::assertSent(LifecycleSetupRewardMail::class, 1);
        $this->assertDatabaseHas('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'drip_reward',
        ]);

        $this->runCommand();
        Mail::assertSent(LifecycleSetupRewardMail::class, 1);
    }

    public function test_d1_is_silent_for_an_unverified_owner(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageDays: 4);
        World::owner($business)->forceFill(['email_verified_at' => null])->save();

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_d1_is_silent_when_the_reward_is_set(): void
    {
        $this->business([], ageDays: 4);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_d1_is_silent_for_a_closed_business(): void
    {
        $this->business(['friend_reward_text' => '', 'status' => 'closed', 'closed_at' => now()], ageDays: 4);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_d1_is_silent_after_the_day_21_window_closes(): void
    {
        $this->business(['friend_reward_text' => ''], ageDays: 22);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_d1_is_silent_when_setup_reminders_are_turned_off(): void
    {
        $this->business(['friend_reward_text' => '', 'setup_reminders_opt_out' => true], ageDays: 4);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_d1_rendered_copy_is_amendment_72(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageDays: 4);

        $this->runCommand();

        $mail = $this->sentMail(LifecycleSetupRewardMail::class);
        $this->assertSame('Set your reward, and your code can work', $mail->envelope()->subject);
        $text = $this->renderedText($mail);
        $this->assertStringContainsString('Your reward is not set yet', $text);
        $this->assertStringContainsString(
            'your shop '.$business->name.' has an Easy Refer code, but it cannot work until you set the reward a new customer gets. Pick one of the ready-made rewards or write your own. It takes about a minute, and you can change it any time.',
            $text,
        );
        $this->assertStringContainsString('Set your reward', $text);
        $this->assertStringContainsString(
            'You are getting this because setup for '.$business->name.' is not finished. Turn off setup reminder emails in Settings, under Notifications. Payment and security emails are never turned off.',
            $text,
        );
        $this->assertStringNotContainsString('Add your card', $text);
        $this->assertStringNotContainsString('steps left', $text);
        $this->assertCleanCopy($text);
    }

    public function test_d1_stale_state_probe_reward_set_between_claim_and_send(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageDays: 4);

        $this->runWithClaimProbe('drip_reward', function (Business $b): void {
            $b->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        });

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'drip_reward',
        ]);
    }

    // ---------------------------------------------------------------
    // D2: poster stall
    // ---------------------------------------------------------------

    public function test_d2_fires_at_day_5_not_day_4_and_the_poster_event_silences_it(): void
    {
        $business = $this->business([], ageDays: 4);

        $this->runCommand();
        Mail::assertNothingSent();

        $created = now()->subDays(5)->subHour();
        $business->forceFill(['created_at' => $created])->save();
        World::owner($business)->forceFill(['created_at' => $created])->save();

        $this->runCommand();
        Mail::assertSent(LifecycleSetupPosterMail::class, 1);

        $mail = $this->sentMail(LifecycleSetupPosterMail::class);
        $this->assertSame('Your code is live. Your poster is the missing piece', $mail->envelope()->subject);
        $text = $this->renderedText($mail);
        $this->assertStringContainsString('Your code works. Now put it where customers can see it', $text);
        $this->assertStringContainsString(
            'Your Easy Refer code for '.$business->name.' is live, and we have not seen a poster taken for it yet. Print the poster or the tent card from your QR page and put it by the register. That is where customers scan it and send it to their friends.',
            $text,
        );
        $this->assertStringContainsString('Get your poster', $text);
        $this->assertStringNotContainsString('drawer', strtolower($text));
        $this->assertCleanCopy($text);

        // A second business whose poster was taken never hears it.
        $other = $this->business([], ageDays: 6);
        $this->event($other, 'poster_downloaded');
        Mail::fake();
        $this->runCommand();
        Mail::assertNotSent(LifecycleSetupPosterMail::class);
    }

    public function test_d2_is_silent_while_the_flow_is_not_live(): void
    {
        $this->business(['friend_reward_text' => ''], ageDays: 6);

        $this->runCommand();

        Mail::assertNotSent(LifecycleSetupPosterMail::class);
    }

    public function test_d2_stale_state_probe_poster_taken_between_claim_and_send(): void
    {
        $business = $this->business([], ageDays: 6);

        $this->runWithClaimProbe('drip_poster', function (Business $b): void {
            ReferralEvent::create(['business_id' => $b->id, 'event_type' => 'poster_downloaded', 'actor' => 'owner']);
        });

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'drip_poster',
        ]);
    }

    // ---------------------------------------------------------------
    // D3: no-scan stall
    // ---------------------------------------------------------------

    public function test_d3_fires_at_day_10_not_day_9_with_days_live_from_the_poster_row(): void
    {
        $business = $this->business([], ageDays: 9);
        $this->event($business, 'poster_downloaded', now()->subDays(8));

        $this->runCommand();
        Mail::assertNothingSent();

        $created = now()->subDays(12);
        $business->forceFill(['created_at' => $created])->save();
        World::owner($business)->forceFill(['created_at' => $created])->save();

        $this->runCommand();
        Mail::assertSent(LifecycleFirstScanMail::class, 1);

        $mail = $this->sentMail(LifecycleFirstScanMail::class);
        $this->assertSame('Try your poster by the register', $mail->envelope()->subject);
        $this->assertStringNotContainsString('0', $mail->envelope()->subject);
        $text = $this->renderedText($mail);
        $this->assertStringContainsString('Your poster is out. The register is its best spot', $text);
        $this->assertStringContainsString(
            'Your code for '.$business->name.' has been live for 8 days, and no customer has scanned it yet. Posters at the register get scanned most, because customers see them while they pay. If your poster is anywhere else, try moving it there. You can also scan your own code and join with your own number, to see exactly what a customer sees.',
            $text,
        );
        $this->assertStringContainsString('See your placements', $text);
        $this->assertStringNotContainsString('Nobody has seen your poster', $text);
        $this->assertStringNotContainsString('placement is wrong', $text);
        $this->assertCleanCopy($text);
    }

    public function test_d3_is_silent_once_a_scan_exists(): void
    {
        $business = $this->business([], ageDays: 12);
        $this->event($business, 'poster_downloaded', now()->subDays(8));
        $this->event($business, 'scan', now()->subDays(2));

        $this->runCommand();

        Mail::assertNotSent(LifecycleFirstScanMail::class);
    }

    public function test_d3_stale_state_probe_first_scan_between_claim_and_send(): void
    {
        $business = $this->business([], ageDays: 12);
        $this->event($business, 'poster_downloaded', now()->subDays(8));

        $this->runWithClaimProbe('drip_first_scan', function (Business $b): void {
            ReferralEvent::create(['business_id' => $b->id, 'event_type' => 'scan', 'actor' => 'customer']);
        });

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'drip_first_scan',
        ]);
    }

    // ---------------------------------------------------------------
    // A1: one free visit left
    // ---------------------------------------------------------------

    public function test_a1_fires_at_four_counted_visits_on_the_walk_not_the_column(): void
    {
        // The C-28 trap, pinned deliberately: the column still
        // reads a full allowance while the walk says 4 used.
        $business = $this->business(['billing_status' => 'none', 'free_visits_remaining' => 5]);
        $this->settleVisits($business, 3);

        $this->runCommand();
        Mail::assertNothingSent();

        $this->settleVisits($business, 1);

        $this->runCommand();
        Mail::assertSent(AllowanceLastVisitMail::class, 1);

        $mail = $this->sentMail(AllowanceLastVisitMail::class);
        $this->assertSame('One of your 5 free visits is left', $mail->envelope()->subject);
        $text = $this->renderedText($mail);
        $this->assertStringContainsString('Your next referred visit is your last free one', $text);
        $this->assertStringContainsString(
            'Good news first: referred friends have visited '.$business->name.' 4 times, and those visits were free. One free visit is left. When one more referred friend visits, your code pauses until you add a card. Add a card now and nothing pauses: visits after your free ones are $2 each, charged once a month, and never more than $99 in a month in visit charges.',
            $text,
        );
        $this->assertStringContainsString('Add your card', $text);
        $this->assertStringNotContainsString('You will be charged', $text);
        $this->assertCleanCopy($text);

        $this->runCommand();
        Mail::assertSent(AllowanceLastVisitMail::class, 1);
    }

    public function test_a1_is_silent_when_the_column_disagrees_the_other_way(): void
    {
        // Column says one left; the walk says three used (two
        // left). The walk governs: no A1.
        $business = $this->business(['billing_status' => 'none', 'free_visits_remaining' => 1]);
        $this->settleVisits($business, 3);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_a1_is_silent_with_a_card_on_file(): void
    {
        $business = $this->business(['billing_status' => 'active']);
        $this->addCard($business);
        $this->settleVisits($business, 4);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_a1_is_silent_while_past_due(): void
    {
        $business = $this->business(['billing_status' => 'past_due']);
        $this->settleVisits($business, 4);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_a1_is_silent_when_the_reward_is_missing(): void
    {
        $business = $this->business(['billing_status' => 'none', 'friend_reward_text' => '']);
        $this->settleVisits($business, 4);

        $this->runCommand();

        Mail::assertNotSent(AllowanceLastVisitMail::class);
    }

    public function test_a1_stale_state_probe_the_fifth_visit_lands_between_claim_and_send(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 4);

        $this->runWithClaimProbe('allowance_last_one', function (Business $b): void {
            $this->settleVisitsFromProbe($b);
        });

        Mail::assertNotSent(AllowanceLastVisitMail::class);
        $this->assertDatabaseMissing('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'allowance_last_one',
        ]);

        // A1 can never follow for this episode: the state moved
        // to the pause, and A2 is the due email instead.
        $this->runCommand();
        Mail::assertNotSent(AllowanceLastVisitMail::class);
        Mail::assertSent(AllowancePausedMail::class, 1);
    }

    /** The probe's fifth visit (the probe closure runs outside the test's $this binding for the service). */
    private function settleVisitsFromProbe(Business $business): void
    {
        $this->settleVisits($business, 1);
    }

    // ---------------------------------------------------------------
    // A2: allowance spent, code paused (+ reminder form)
    // ---------------------------------------------------------------

    public function test_a2_fires_at_the_fifth_visit_not_a1_and_only_once(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 5);

        $this->runCommand();

        Mail::assertNotSent(AllowanceLastVisitMail::class);
        Mail::assertSent(AllowancePausedMail::class, 1);

        $mail = $this->sentMail(AllowancePausedMail::class);
        $this->assertSame('Your Easy Refer code has paused', $mail->envelope()->subject);
        $text = $this->renderedText($mail);
        $this->assertStringContainsString('Your 5 free visits are used. A card turns your code back on', $text);
        $this->assertStringContainsString(
            'Referred friends have now visited '.$business->name.' 5 times, and your first 5 visits were free, as promised. Because there is no card on file yet, your code has paused: new customers who scan it will see that it is paused. Rewards your customers already earned still work as usual. Add a card and your code turns back on right away. From then on it is $2 when a referred friend visits, charged once a month.',
            $text,
        );
        $this->assertStringNotContainsString('cannot redeem', $text);
        $this->assertStringNotContainsString('has stopped', $text);
        $this->assertCleanCopy($text);

        // Back-to-back sweeps: still exactly one (the claim race
        // pin: the ledger's unique key under a double sweep).
        $this->runCommand();
        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 1);
        $this->assertSame(1, LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->where('send_key', 'allowance_paused')
            ->count());
    }

    public function test_a2_reminder_fires_once_at_day_7_and_never_a_third_time(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 5);

        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 1);

        $this->travel(6)->days();
        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 1);

        $this->travel(1)->days();
        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 2);

        $reminder = null;
        Mail::assertSent(AllowancePausedMail::class, function (AllowancePausedMail $mail) use (&$reminder) {
            if ($mail->reminder) {
                $reminder = $mail;
            }

            return true;
        });
        $this->assertNotNull($reminder);
        $this->assertSame('Still paused: your Easy Refer code', $reminder->envelope()->subject);
        $text = $this->renderedText($reminder);
        $this->assertStringContainsString(
            'A week ago we wrote that your code for '.$business->name.' had paused, because your 5 free visits are used and there is no card on file yet. It is still paused. Add a card and it turns back on right away. Rewards your customers already earned still work as usual.',
            $text,
        );
        $this->assertStringNotContainsString('final notice', strtolower($text));
        $this->assertCleanCopy($text);

        $this->travel(7)->days();
        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 2);
    }

    public function test_a2_reminder_uses_the_over_a_week_form_after_day_10(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 5);

        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 1);

        $this->travel(11)->days();
        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 2);

        $reminder = null;
        Mail::assertSent(AllowancePausedMail::class, function (AllowancePausedMail $mail) use (&$reminder) {
            if ($mail->reminder) {
                $reminder = $mail;
            }

            return true;
        });
        $this->assertNotNull($reminder);
        $this->assertStringContainsString('Over a week ago we wrote', $this->renderedText($reminder));
    }

    public function test_a2_reminder_never_fires_when_a_card_arrives_at_day_6(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 5);

        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 1);

        $this->travel(6)->days();
        $this->addCard($business);

        $this->travel(2)->days();
        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 1);
    }

    public function test_a2_sends_to_an_unverified_owner_the_named_corner(): void
    {
        // Pass Section 7.3: the drip never runs unverified, but
        // A2 sends: a paused code is money-class truth the
        // owner needs at whatever address is on file.
        $business = $this->business(['billing_status' => 'none'], ageDays: 4);
        World::owner($business)->forceFill(['email_verified_at' => null])->save();
        $this->settleVisits($business, 5);

        $this->runCommand();

        Mail::assertSent(AllowancePausedMail::class, 1);
        Mail::assertNotSent(LifecycleSetupRewardMail::class);
        Mail::assertNotSent(LifecycleSetupPosterMail::class);
    }

    public function test_a2_still_sends_when_setup_reminders_are_turned_off(): void
    {
        // A2 is service-critical money mail, not drip: the
        // drip turn-off does not reach it (pass Section 7.5).
        $business = $this->business(['billing_status' => 'none', 'setup_reminders_opt_out' => true]);
        $this->settleVisits($business, 5);

        $this->runCommand();

        Mail::assertSent(AllowancePausedMail::class, 1);
    }

    public function test_a2_is_silent_while_past_due(): void
    {
        $business = $this->business(['billing_status' => 'past_due']);
        $this->settleVisits($business, 5);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_a2_stale_state_probe_a_card_arrives_between_claim_and_send(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 5);

        $this->runWithClaimProbe('allowance_paused', function (Business $b): void {
            PaymentMethod::create([
                'business_id' => $b->id,
                'square_card_id' => 'card_probe_'.$b->id,
                'card_brand' => 'Visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
                'is_default' => true,
                'state' => PaymentMethod::STATE_SUCCEEDED,
            ]);
        });

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'allowance_paused',
        ]);
    }

    // ---------------------------------------------------------------
    // C1: card has expired
    // ---------------------------------------------------------------

    public function test_c1_fires_once_per_card_with_amendment_72_copy(): void
    {
        $business = $this->business(['billing_status' => 'active']);
        $card = $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);

        $this->runCommand();
        Mail::assertSent(CardExpiredMail::class, 1);
        $this->assertDatabaseHas('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'card_expired_'.$card->id,
        ]);

        $mail = $this->sentMail(CardExpiredMail::class);
        $this->assertSame('Your card on file has expired', $mail->envelope()->subject);
        $text = $this->renderedText($mail);
        $this->assertStringContainsString('Replace your card before your next charge', $text);
        $this->assertStringContainsString(
            'The card on file for '.$business->name.', Visa ···· 4242, expired in May 2026. Your code keeps working. When your next monthly charge runs, it will not go through on this card, and your code would pause while the payment is sorted out. Replace the card now and none of that happens.',
            $text,
        );
        $this->assertStringContainsString('Update your card', $text);
        $this->assertStringNotContainsString('payment failed', strtolower($text));
        $this->assertStringNotContainsString('past due', strtolower($text));
        $this->assertCleanCopy($text);

        // Re-sweeps never re-send for the same card.
        $this->runCommand();
        $this->runCommand();
        Mail::assertSent(CardExpiredMail::class, 1);
    }

    public function test_c1_cuts_the_keeps_working_sentence_when_the_flow_is_off(): void
    {
        $business = $this->business(['billing_status' => 'active', 'friend_reward_text' => '']);
        $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);

        $this->runCommand();

        $mail = $this->sentMail(CardExpiredMail::class);
        $this->assertStringNotContainsString('Your code keeps working.', $this->renderedText($mail));
    }

    public function test_c1_is_silent_when_the_card_was_replaced_before_the_run(): void
    {
        $business = $this->business(['billing_status' => 'active']);
        $old = $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);
        $old->forceFill(['is_default' => false])->save();
        $this->addCard($business);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_c1_is_silent_while_past_due(): void
    {
        $business = $this->business(['billing_status' => 'past_due']);
        $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_c1_a_second_card_earns_its_own_send_years_later(): void
    {
        $business = $this->business(['billing_status' => 'active']);
        $first = $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);

        $this->runCommand();
        Mail::assertSent(CardExpiredMail::class, 1);

        $first->forceFill(['is_default' => false])->save();
        $second = $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026, 'last4' => '1005']);

        // The drip-class ceiling holds the second card's C1
        // until the spacing window passes; at +3 days it fires
        // under its own per-card key.
        $this->travel(3)->days();
        $this->runCommand();

        Mail::assertSent(CardExpiredMail::class, 2);
        $this->assertDatabaseHas('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'card_expired_'.$second->id,
        ]);
    }

    public function test_c1_stale_state_probe_the_card_is_replaced_between_claim_and_send(): void
    {
        $business = $this->business(['billing_status' => 'active']);
        $old = $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);

        $this->runWithClaimProbe('card_expired_'.$old->id, function (Business $b) use ($old): void {
            $old->forceFill(['is_default' => false])->save();
            PaymentMethod::create([
                'business_id' => $b->id,
                'square_card_id' => 'card_probe_new_'.$b->id,
                'card_brand' => 'Visa',
                'last4' => '1005',
                'exp_month' => 12,
                'exp_year' => 2030,
                'is_default' => true,
                'state' => PaymentMethod::STATE_SUCCEEDED,
            ]);
        });

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'card_expired_'.$old->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Protocol: claim race, transport failure, caps, collisions
    // ---------------------------------------------------------------

    public function test_a_pre_claimed_ledger_row_blocks_the_send(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageDays: 4);
        $this->ledgerRow($business, 'drip_reward');

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_a_transport_failure_releases_the_claim_and_the_next_run_sends(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageDays: 4);

        $fakeRoot = Mail::getFacadeRoot();
        $manager = $fakeRoot instanceof \Illuminate\Support\Testing\Fakes\MailFake
            ? $fakeRoot->manager
            : $this->app->make(\Illuminate\Mail\MailManager::class);
        $throwing = new class($manager) extends \Illuminate\Support\Testing\Fakes\MailFake
        {
            public function send($view, array $data = [], $callback = null)
            {
                throw new \RuntimeException('mail transport down');
            }
        };
        Mail::swap($throwing);

        app(LifecycleMailService::class)->run();

        $this->assertDatabaseMissing('lifecycle_email_sends', [
            'business_id' => $business->id, 'send_key' => 'drip_reward',
        ]);

        Mail::fake();
        $this->runCommand();
        Mail::assertSent(LifecycleSetupRewardMail::class, 1);
    }

    public function test_the_ceiling_blocks_a_third_drip_class_send_in_seven_days(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 4);
        $this->ledgerRow($business, 'drip_reward', now()->subDays(3));
        $this->ledgerRow($business, 'drip_poster', now()->subDays(3));

        $this->runCommand();
        Mail::assertNothingSent();

        // Eight days on, the window has rolled past both sends.
        $this->travel(8)->days();
        $this->runCommand();
        Mail::assertSent(AllowanceLastVisitMail::class, 1);
    }

    public function test_the_nudge_counts_toward_the_ceiling(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 4);
        $this->ledgerRow($business, 'drip_reward', now()->subDays(3));
        World::owner($business)->forceFill(['welcome_nudge_sent_at' => now()->subDays(3)])->save();

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_the_48_hour_spacing_blocks_and_then_releases(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 4);
        $this->ledgerRow($business, 'drip_poster', now()->subHours(24));

        $this->runCommand();
        Mail::assertNothingSent();

        $this->travel(25)->hours();
        $this->runCommand();
        Mail::assertSent(AllowanceLastVisitMail::class, 1);
    }

    public function test_a_money_email_today_defers_a_due_drip_email_to_the_next_day(): void
    {
        $business = $this->business([], ageDays: 6);
        AuditLog::write('billing.dunning_email', null, ['stage' => 'day_0'], $business);

        $this->runCommand();
        Mail::assertNothingSent();

        $this->travel(1)->days();
        $this->runCommand();
        Mail::assertSent(LifecycleSetupPosterMail::class, 1);
    }

    public function test_an_m5_send_today_holds_other_drip_class_mail_until_tomorrow(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 4);
        // An A2 (money-class) already sent this business-local
        // day: two M-5 emails never share a day for one owner.
        $this->ledgerRow($business, 'allowance_paused', now());

        $this->runCommand();
        Mail::assertNothingSent();

        $this->travel(1)->days();
        $this->runCommand();
        Mail::assertSent(AllowanceLastVisitMail::class, 1);
    }

    public function test_the_send_window_holds_a_night_send_until_morning(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageDays: 4);

        // 03:30 UTC is 23:30 the previous evening in New York.
        $this->travelTo(Carbon::parse('2026-06-15 03:30:00', 'UTC'));
        $this->runCommand();
        Mail::assertNothingSent();

        // 12:30 UTC is 08:30 in New York: inside the window.
        $this->travelTo(Carbon::parse('2026-06-15 12:30:00', 'UTC'));
        $this->runCommand();
        Mail::assertSent(LifecycleSetupRewardMail::class, 1);
    }

    // ---------------------------------------------------------------
    // MEASURE stamps + the Settings turn-off
    // ---------------------------------------------------------------

    public function test_step_completion_is_stamped_by_a_later_sweep(): void
    {
        $business = $this->business(['friend_reward_text' => ''], ageDays: 4);

        $this->runCommand();
        Mail::assertSent(LifecycleSetupRewardMail::class, 1);
        $row = LifecycleEmailSend::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertNull($row->step_completed_at);

        $business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $this->travel(2)->hours();
        $this->runCommand();

        $this->assertNotNull($row->fresh()->step_completed_at);
    }

    public function test_a2_completion_is_stamped_when_the_card_arrives(): void
    {
        $business = $this->business(['billing_status' => 'none']);
        $this->settleVisits($business, 5);

        $this->runCommand();
        Mail::assertSent(AllowancePausedMail::class, 1);

        $this->addCard($business);
        $this->travel(1)->hours();
        $this->runCommand();

        $row = LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->where('send_key', 'allowance_paused')
            ->firstOrFail();
        $this->assertNotNull($row->step_completed_at);
    }

    public function test_c1_completion_is_not_stamped_when_the_expired_card_is_removed_without_replacement(): void
    {
        // Review note N2: Section 9.5 defines C1 completion as
        // REPLACEMENT. Removing the expired card with no new
        // default leaves the default NULL, which is not a
        // replacement, so the C1 row must stay unstamped.
        $business = $this->business(['billing_status' => 'active']);
        $card = $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);

        $this->runCommand();
        Mail::assertSent(CardExpiredMail::class, 1);
        $row = LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->where('send_key', 'card_expired_'.$card->id)
            ->firstOrFail();
        $this->assertNull($row->step_completed_at);

        $card->forceFill([
            'state' => PaymentMethod::STATE_REMOVED,
            'is_default' => false,
        ])->save();
        $this->travel(1)->hours();
        $this->runCommand();

        $this->assertNull($row->fresh()->step_completed_at);
    }

    public function test_c1_completion_is_stamped_when_the_expired_card_is_replaced(): void
    {
        $business = $this->business(['billing_status' => 'active']);
        $card = $this->addCard($business, ['exp_month' => 5, 'exp_year' => 2026]);

        $this->runCommand();
        Mail::assertSent(CardExpiredMail::class, 1);
        $row = LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->where('send_key', 'card_expired_'.$card->id)
            ->firstOrFail();
        $this->assertNull($row->step_completed_at);

        $card->forceFill(['is_default' => false])->save();
        $this->addCard($business);
        $this->travel(1)->hours();
        $this->runCommand();

        $this->assertNotNull($row->fresh()->step_completed_at);
    }

    public function test_the_settings_toggle_writes_only_the_new_column(): void
    {
        $business = $this->business([]);
        $owner = World::owner($business);

        $this->actingAs($owner)
            ->post(route('settings.notifications.update'), [
                'owner_text_notifications' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $business->fresh()->setup_reminders_opt_out);

        $this->actingAs($owner)
            ->post(route('settings.notifications.update'), [
                'owner_text_notifications' => '1',
                'setup_reminders' => '1',
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $business->fresh()->setup_reminders_opt_out);
    }
}

/**
 * The stale-state probe service: moves state inside the
 * claim-to-send window (pass Section 4.3's race set) so the
 * pins above prove the post-claim re-read, not just the
 * eligibility scan.
 */
class ProbingLifecycleMailService extends LifecycleMailService
{
    public static ?\Closure $probe = null;

    protected function afterClaim(Business $business, string $key): void
    {
        if (self::$probe !== null) {
            (self::$probe)($business, $key);
        }
    }
}
