<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningRecoveredMail;
use App\Mail\DunningReminderMail;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Referral;
use App\Services\Billing\SquareWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Dunning (spec Section 9.6, Amendment 3 / F8; copy inventory
 * DUN-01..DUN-07): when a webhook-driven failure puts a business
 * past_due, the owner gets the day 0 email with the failed
 * record's amount, the grace end date (past_due entry + the
 * specced 7 days), and the billing link; a reminder on day 3;
 * the pause warning on day 6; and a success email quoting the
 * recovered record's own figures when the business reactivates.
 * Every send is audit-written, and the audit rows are the
 * idempotency record: a stage is never sent twice for one
 * episode. No dunning email ever goes to a customer, and none
 * goes to a business that is not past_due.
 */
class DunningTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 9000;

    /** Episode start used across the schedule tests. */
    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
        Mail::fake();
        $this->t0 = Carbon::parse('2026-03-10 12:00:00');
    }

    private function business(array $overrides = []): Business
    {
        return World::business(array_merge([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
        ], $overrides));
    }

    private function record(Business $business, string $state, int $amountCents, array $overrides = []): BillingRecord
    {
        return BillingRecord::create(array_merge([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => $amountCents,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_test_'.Str::random(8),
            'state' => $state,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ], $overrides));
    }

    /** Attach coverage events to a record through real referral chains. */
    private function attachEvents(Business $business, BillingRecord $record, int $billable, int $freeCredit): void
    {
        $referrer = World::customer('+12125550987', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        foreach (['billable' => $billable, 'free_credit' => $freeCredit] as $coverage => $count) {
            for ($i = 0; $i < $count; $i++) {
                $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
                [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED]);
                BillingEvent::create([
                    'business_id' => $business->id,
                    'referral_id' => $referral->id,
                    'billing_record_id' => $record->id,
                    'amount_cents' => 200,
                    'coverage' => $coverage,
                ]);
            }
        }
    }

    /** Drive the real webhook handler the way BU-8's endpoint does. */
    private function deliverPayment(Business $business, string $paymentId, string $status): void
    {
        app(SquareWebhookHandler::class)->handle([
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => $paymentId, 'status' => $status, 'customer_id' => $business->square_customer_id,
            ]]],
        ]);
    }

    private function runDunning(): void
    {
        $this->artisan('billing:dunning')->assertExitCode(0);
    }

    private function renderMail(Mailable $mail): string
    {
        $content = $mail->content();

        return view($content->view, $content->with)->render();
    }

    /** The email's visible text: tags stripped, whitespace collapsed. */
    private function renderMailText(Mailable $mail): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($this->renderMail($mail))));
    }

    public function test_an_active_business_gets_no_dunning_email(): void
    {
        $this->business();

        $this->runDunning();

        Mail::assertNothingSent();
        $this->assertSame(0, AuditLog::query()->where('action', 'billing.dunning_email')->count());
    }

    public function test_day_zero_email_follows_past_due_entry_with_the_records_figures(): void
    {
        $this->travelTo($this->t0);
        $business = $this->business();
        $record = $this->record($business, BillingRecord::STATE_PENDING, 4200);

        $this->deliverPayment($business, 'sq_pay_failed', 'FAILED');
        $this->assertSame('past_due', $business->fresh()->billing_status);
        $this->assertSame(BillingRecord::STATE_FAILED, $record->fresh()->state);

        $this->runDunning();

        $owner = World::owner($business);
        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) use ($owner) {
            $this->assertTrue($mail->hasTo($owner->email));
            // DUN-01, verbatim.
            $this->assertSame('Your Easy Refer payment did not go through', $mail->envelope()->subject);
            // DUN-02, verbatim: the failed record's amount, the
            // grace end (entry Mar 10 + 7 days), the billing link
            // resolved through the route at this mount.
            $this->assertStringContainsString(
                'Your Easy Refer charge of $42 did not go through. Your QR code keeps working until Mar 17, 2026. '
                .'Update your card to keep billing current: '.route('settings.billing'),
                $this->renderMailText($mail)
            );

            return true;
        });
        Mail::assertSentCount(1);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'billing.dunning_email')
            ->where('business_id', $business->id)
            ->where('metadata->stage', 'day0')
            ->count());
    }

    public function test_reminders_follow_the_schedule_and_never_repeat(): void
    {
        $this->travelTo($this->t0);
        $business = $this->business();
        $this->record($business, BillingRecord::STATE_PENDING, 4200);
        $this->deliverPayment($business, 'sq_pay_failed', 'FAILED');

        $this->runDunning();
        Mail::assertSentCount(1);

        // Day 2: nothing new is due.
        $this->travelTo($this->t0->copy()->addDays(2));
        $this->runDunning();
        Mail::assertSentCount(1);

        // Day 3: the reminder (DUN-03 subject, verbatim).
        $this->travelTo($this->t0->copy()->addDays(3));
        $this->runDunning();
        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) {
            $this->assertSame('Reminder: update your card by Mar 17, 2026', $mail->envelope()->subject);

            return true;
        });
        Mail::assertSentCount(2);

        // Same day again: the audit-backed send record holds.
        $this->runDunning();
        Mail::assertSentCount(2);

        // Day 6: the pause warning (DUN-04 subject, DUN-05 line).
        $this->travelTo($this->t0->copy()->addDays(6));
        $this->runDunning();
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) {
            $this->assertSame('Your Easy Refer billing pauses on Mar 17, 2026', $mail->envelope()->subject);
            $this->assertStringContainsString(
                'If the charge does not succeed, new signups pause on Mar 17, 2026. '
                .'Customers who already earned rewards can still redeem them.',
                $this->renderMailText($mail)
            );

            return true;
        });
        Mail::assertSentCount(3);

        $this->runDunning();
        Mail::assertSentCount(3);
    }

    public function test_recovery_sends_the_success_email_with_the_records_figures_and_closes_the_episode(): void
    {
        // February: a batch whose events live in February, so the
        // March estimate below counts only the recovered record's
        // own month, exactly as the statement page computes it.
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        $business = $this->business(['average_ticket_cents' => 1000]);
        $failed = $this->record($business, BillingRecord::STATE_PENDING, 1800, [
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
            'idempotency_key' => 'perref_'.$business->id.'_2026-02-01_1',
        ]);
        $this->attachEvents($business, $failed, billable: 2, freeCredit: 0);

        // Mar 10: the February charge fails at Square; past_due.
        $this->travelTo($this->t0);
        $this->deliverPayment($business, 'sq_pay_failed', 'FAILED');
        $this->assertSame('past_due', $business->fresh()->billing_status);

        // Mar 12: the rebuilt March batch succeeds; reactivation.
        $this->travelTo($this->t0->copy()->addDays(2));
        $recovered = $this->record($business, BillingRecord::STATE_PENDING, 800);
        $this->attachEvents($business, $recovered, billable: 4, freeCredit: 1);
        $this->deliverPayment($business, 'sq_pay_recovered', 'COMPLETED');
        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $recovered->fresh()->state);

        $this->runDunning();

        // Only the success email: the closed episode draws no
        // stage mail even though its day 0 was never sent.
        Mail::assertSentCount(1);
        Mail::assertSent(DunningRecoveredMail::class, function (DunningRecoveredMail $mail) {
            // DUN-06, verbatim.
            $this->assertSame('Your Easy Refer payment went through', $mail->envelope()->subject);
            // DUN-07, verbatim: 4 visits that counted on the
            // recovered record; the value estimate is the
            // statement page's own computation (5 March events
            // at the business's $10 average ticket).
            $this->assertStringContainsString(
                'This charge covered 4 visits that counted, worth about $50 in first-visit sales (estimate).',
                $this->renderMailText($mail)
            );

            return true;
        });

        // And it is never sent twice for the same reactivation.
        $this->runDunning();
        Mail::assertSentCount(1);
    }

    public function test_episode_with_no_tied_record_uses_the_latest_failed_record_for_the_amount(): void
    {
        // A record the local charger already failed definitively
        // (which never sets past_due by itself), then a Square
        // failure for a payment no local record names: BU-8's
        // handler still sets past_due, with no record tied to
        // the audit row.
        $this->travelTo($this->t0->copy()->subDay());
        $business = $this->business();
        $this->record($business, BillingRecord::STATE_FAILED, 1800);

        $this->travelTo($this->t0);
        $this->deliverPayment($business, 'sq_pay_unknown', 'FAILED');
        $this->assertSame('past_due', $business->fresh()->billing_status);

        $this->runDunning();

        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) {
            $this->assertStringContainsString(
                'Your Easy Refer charge of $18 did not go through.',
                $this->renderMailText($mail)
            );

            return true;
        });
    }

    public function test_episode_with_no_record_anywhere_sends_nothing(): void
    {
        $this->travelTo($this->t0);
        $business = $this->business();
        $this->deliverPayment($business, 'sq_pay_unknown', 'FAILED');
        $this->assertSame('past_due', $business->fresh()->billing_status);

        $this->runDunning();

        Mail::assertNothingSent();
        $this->assertSame(0, AuditLog::query()->where('action', 'billing.dunning_email')->count());
    }
}
