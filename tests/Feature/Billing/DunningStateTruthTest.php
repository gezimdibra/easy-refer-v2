<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningReminderMail;
use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Support\BillingCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train A Unit 5 (F-03, truth sweep 2026-10-28-01): the dunning
 * stage emails are surfaces of the same past-due lifecycle
 * Train A fixed in-app (SA-01, Amendment 35). The stage mails
 * still send to closed and disabled past-due businesses, and
 * that audience is deliberate: the amount is really owed and
 * owed months still drain after closure (ClosedBusinessBilling
 * AuditTest (c) pins the full schedule against a closed
 * business). What may not survive is the wording: a closed or
 * disabled business's code is already off, and a card update
 * cannot restart it, so the keeps-working / stops-working /
 * pause-on lines are false in those states. Amendment 36: the
 * stage mails branch on the business's status at send time.
 * Closed additionally loses the Update-your-card button: the
 * closed middleware refuses the card endpoints, so the button
 * would be a dead action (ClosedBusinessBillingAuditTest (b)).
 * A disabled owner CAN still update the card, so the button
 * stays for them; what goes is every claim that updating it
 * restarts or preserves the flow.
 */
class DunningStateTruthTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
        Mail::fake();
        $this->t0 = Carbon::parse('2026-03-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /** A past-due business with a real failed $42 record and episode. */
    private function pastDueBusiness(array $overrides = []): Business
    {
        $this->travelTo($this->t0);
        $business = World::business(array_merge([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_u5',
            'free_visits_remaining' => 0,
        ], $overrides));
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 4200,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_u5_'.Str::random(6),
            'state' => BillingRecord::STATE_PENDING,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);
        app(\App\Services\Billing\SquareWebhookHandler::class)->handle([
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'sq_pay_u5_failed', 'status' => 'FAILED', 'customer_id' => 'sq_customer_u5',
            ]]],
        ]);
        $this->assertSame('past_due', $business->fresh()->billing_status);

        return $business->fresh();
    }

    private function runDunning(): void
    {
        $this->artisan('billing:dunning')->assertExitCode(0);
    }

    private function renderMailText(Mailable $mail): string
    {
        $content = $mail->content();

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(view($content->view, $content->with)->render())));
    }

    /**
     * Run the full stage schedule for the business and collect
     * every stage mail's rendered text, keyed by stage.
     *
     * @return array{day0: string, day3: string, day6: string}
     */
    private function stageMailTexts(): array
    {
        $texts = ['day0' => '', 'day3' => '', 'day6' => ''];

        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) use (&$texts) {
            $texts['day0'] = $this->renderMailText($mail);

            return true;
        });
        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) use (&$texts) {
            $texts['day3'] = $this->renderMailText($mail);

            return true;
        });
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) use (&$texts) {
            $texts['day6'] = $this->renderMailText($mail);

            return true;
        });

        return $texts;
    }

    private function runFullSchedule(): void
    {
        $this->runDunning();
        $this->travelTo($this->t0->copy()->addDays(3));
        $this->runDunning();
        $this->travelTo($this->t0->copy()->addDays(6));
        $this->runDunning();
        Mail::assertSentCount(3);
    }

    // (a) Closed: every stage mail still sends (the owed amount
    // is real), but no stage mail may say the code keeps
    // working, stops working, or pauses on a date; the truthful
    // branch (off line + amount + still-due) is asserted
    // positively in each one.
    public function test_closed_past_due_business_gets_the_off_branch_in_every_stage_email(): void
    {
        $business = $this->pastDueBusiness();
        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->runFullSchedule();

        foreach ($this->stageMailTexts() as $stage => $text) {
            $this->assertStringContainsString('Your Easy Refer charge of $42 did not go through.', $text, $stage);
            $this->assertStringContainsString('Your QR code and customer links are off.', $text, $stage);
            $this->assertStringContainsString('Amount due $42', $text, $stage);
            $this->assertStringContainsString('Your QR code Off', $text, $stage);
            // OB-02 (Amendment 45): this fixture has no card on
            // file, so the retry promise is gone; a closed
            // business gets the bare still-due sentence (it has
            // no card action to offer).
            $this->assertStringContainsString('This amount is still due.', $text, $stage);
            $this->assertStringNotContainsString('We will try the card on file again', $text, $stage);
            $this->assertStringNotContainsString('keeps working', $text, $stage);
            $this->assertStringNotContainsString('Keeps working', $text, $stage);
            $this->assertStringNotContainsString('stops working', $text, $stage);
            $this->assertStringNotContainsString('new signups pause', $text, $stage);
            $this->assertStringNotContainsString('billing pauses', $text, $stage);
            // A closed owner cannot update the card (the closed
            // middleware refuses the card endpoints), so the
            // email may not offer that action or its deadline.
            $this->assertStringNotContainsString('Update your card', $text, $stage);
            $this->assertStringNotContainsString('Update your card by', $text, $stage);
        }

        // The dated subjects are branched too: no deadline and
        // no pause date is asserted to a closed business.
        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) {
            $this->assertSame('Reminder: your Easy Refer payment is still due', $mail->envelope()->subject);

            return true;
        });
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) {
            $this->assertSame('Your Easy Refer payment is still due', $mail->envelope()->subject);

            return true;
        });
    }

    // (c) Disabled: the same off branch for every flow claim,
    // but the Update-your-card action stays, because a disabled
    // owner can still replace the card and the arrears charge
    // will try it; only the restart/preserve claims go.
    public function test_disabled_past_due_business_gets_the_off_branch_with_the_card_action_kept(): void
    {
        $business = $this->pastDueBusiness();
        $business->forceFill(['status' => 'disabled'])->save();

        $this->runFullSchedule();

        foreach ($this->stageMailTexts() as $stage => $text) {
            $this->assertStringContainsString('Your Easy Refer charge of $42 did not go through.', $text, $stage);
            $this->assertStringContainsString('Your QR code and customer links are off.', $text, $stage);
            $this->assertStringContainsString('Amount due $42', $text, $stage);
            // OB-02 (Amendment 45): cardless, so no retry is
            // promised; a disabled owner can still add a card,
            // so the tail names that action.
            $this->assertStringContainsString('This amount is still due. Add a card so we can collect it.', $text, $stage);
            $this->assertStringNotContainsString('We will try the card on file again', $text, $stage);
            $this->assertStringContainsString('Update your card', $text, $stage);
            $this->assertStringNotContainsString('keeps working', $text, $stage);
            $this->assertStringNotContainsString('Keeps working', $text, $stage);
            $this->assertStringNotContainsString('stops working', $text, $stage);
            $this->assertStringNotContainsString('new signups pause', $text, $stage);
            $this->assertStringNotContainsString('billing pauses', $text, $stage);
            $this->assertStringNotContainsString('Update your card by', $text, $stage);
        }

        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) {
            $this->assertSame('Reminder: your Easy Refer payment is still due', $mail->envelope()->subject);

            return true;
        });
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) {
            $this->assertSame('Your Easy Refer payment is still due', $mail->envelope()->subject);

            return true;
        });
    }

    // (b) Active past-due: the stage mails are unchanged in
    // truth. The dated keeps-working line names the same
    // business-local instant the in-app SA-01 line names
    // (BillingCopy::statusLine renders it in full-month form;
    // the mails in short form; the date is identical).
    public function test_active_past_due_stage_emails_keep_the_dated_line_matching_the_in_app_date(): void
    {
        $business = $this->pastDueBusiness();

        // The in-app line for this episode, read at day 0.
        $inApp = BillingCopy::statusLine($business->fresh());
        $this->assertStringContainsString('March 17, 2026', $inApp);

        $this->runFullSchedule();

        foreach ($this->stageMailTexts() as $stage => $text) {
            $this->assertStringContainsString(
                'Your Easy Refer charge of $42 did not go through. Your QR code keeps working until Mar 17, 2026.',
                $text, $stage
            );
            $this->assertStringContainsString('Update your card by Mar 17, 2026', $text, $stage);
            $this->assertStringContainsString('Your QR code Keeps working until then', $text, $stage);
            $this->assertStringNotContainsString('customer links are off', $text, $stage);
        }

        $texts = $this->stageMailTexts();
        $this->assertStringContainsString(
            'If the card is not updated by Mar 17, 2026, billing pauses and your QR code stops working until it is.',
            $texts['day0']
        );
        $this->assertStringContainsString(
            'If the charge does not succeed, new signups pause on Mar 17, 2026. Customers who already earned rewards can still redeem them.',
            $texts['day6']
        );

        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) {
            $this->assertSame('Reminder: update your card by Mar 17, 2026', $mail->envelope()->subject);

            return true;
        });
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) {
            $this->assertSame('Your Easy Refer billing pauses on Mar 17, 2026', $mail->envelope()->subject);

            return true;
        });
    }

    // Abuse: closing mid-episode cannot keep the keeps-working
    // wording coming. The branch reads the status at send time,
    // so the day 0 mail (sent while active) is the active form
    // and the day 3 / day 6 mails (sent after closure) are the
    // off form.
    public function test_abuse_closing_mid_episode_switches_the_remaining_stage_emails_to_the_off_branch(): void
    {
        $business = $this->pastDueBusiness();

        $this->runDunning();
        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) {
            $this->assertStringContainsString('keeps working until Mar 17, 2026', $this->renderMailText($mail));

            return true;
        });

        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->travelTo($this->t0->copy()->addDays(3));
        $this->runDunning();
        $this->travelTo($this->t0->copy()->addDays(6));
        $this->runDunning();
        Mail::assertSentCount(3);

        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) {
            $text = $this->renderMailText($mail);
            $this->assertStringContainsString('Your QR code and customer links are off.', $text);
            $this->assertStringNotContainsString('keeps working', $text);

            return true;
        });
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) {
            $text = $this->renderMailText($mail);
            $this->assertStringContainsString('Your QR code and customer links are off.', $text);
            $this->assertStringNotContainsString('keeps working', $text);

            return true;
        });
    }

    // Abuse: the branch is not a one-way label either. A
    // business the platform re-enables mid-episode goes back to
    // the active form for the stages that have not sent yet, so
    // a stale disabled state cannot suppress the true dated
    // line from a live program.
    public function test_abuse_reenabling_mid_episode_restores_the_active_form_for_unsent_stages(): void
    {
        $business = $this->pastDueBusiness();
        $business->forceFill(['status' => 'disabled'])->save();

        $this->runDunning();
        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) {
            $this->assertStringContainsString('customer links are off.', $this->renderMailText($mail));

            return true;
        });

        $business->forceFill(['status' => 'active'])->save();

        $this->travelTo($this->t0->copy()->addDays(3));
        $this->runDunning();

        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) {
            $text = $this->renderMailText($mail);
            $this->assertStringContainsString('keeps working until Mar 17, 2026', $text);
            $this->assertStringNotContainsString('customer links are off', $text);

            return true;
        });
    }

    // Abuse: toggling status cannot harvest extra stage mails
    // or dodge the owed-amount notice. However often the run
    // executes and however the status moves, each stage sends
    // exactly once per episode (the audit-backed idempotency
    // record), and the closed business still receives all
    // three amount notices.
    public function test_abuse_status_toggling_cannot_duplicate_or_dodge_stage_emails(): void
    {
        $business = $this->pastDueBusiness();

        $this->runDunning();
        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->runDunning();

        $this->travelTo($this->t0->copy()->addDays(3));
        $this->runDunning();
        $business->forceFill(['status' => 'disabled'])->save();
        $this->runDunning();

        $this->travelTo($this->t0->copy()->addDays(6));
        $this->runDunning();
        $this->runDunning();

        Mail::assertSentCount(3);
        $this->assertSame(3, AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'billing.dunning_email')
            ->count());
        foreach ($this->stageMailTexts() as $stage => $text) {
            $this->assertStringContainsString('Amount due $42', $text, $stage);
        }
    }
}
