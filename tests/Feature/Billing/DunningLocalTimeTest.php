<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningReminderMail;
use App\Models\BillingRecord;
use App\Models\Business;
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
 * Train 3.5 (retro 2026-10-25, business-logic audit Section 4
 * seam 5): the dunning stage and pause dates were the raw
 * UTC-instant arithmetic output (episode start plus N days,
 * formatted in UTC). An episode that starts at 01:00 UTC has
 * its grace end at 01:00 UTC a week later, which a US owner
 * experiences on the previous local evening, so the emails
 * named a date one day later than the owner's own. The stage
 * selection arithmetic does not move; the dates the three
 * stage emails print now render through Business::localTime().
 */
class DunningLocalTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
        Mail::fake();
    }

    private function business(string $timezone): Business
    {
        return World::business([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_1',
            'free_visits_remaining' => 0,
            'timezone' => $timezone,
        ]);
    }

    private function record(Business $business): BillingRecord
    {
        return BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 4200,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_test_'.Str::random(8),
            'state' => BillingRecord::STATE_PENDING,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);
    }

    private function failCharge(Business $business): void
    {
        app(SquareWebhookHandler::class)->handle([
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'sq_pay_failed', 'status' => 'FAILED', 'customer_id' => $business->square_customer_id,
            ]]],
        ]);
    }

    private function renderMailText(Mailable $mail): string
    {
        $content = $mail->content();

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(view($content->view, $content->with)->render())));
    }

    public function test_stage_dates_render_in_business_local_time_for_a_new_york_business(): void
    {
        // Episode starts Mar 10, 01:00 UTC (Mar 9, 21:00 EDT);
        // the grace end instant is Mar 17, 01:00 UTC, which is
        // Mar 16, 21:00 in New York.
        $t0 = Carbon::parse('2026-03-10 01:00:00');
        $this->travelTo($t0);
        $business = $this->business('America/New_York');
        $this->record($business);
        $this->failCharge($business);
        $this->assertSame('past_due', $business->fresh()->billing_status);

        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) {
            $this->assertStringContainsString(
                'Your QR code keeps working until Mar 16, 2026.',
                $this->renderMailText($mail)
            );

            return true;
        });

        $this->travelTo($t0->copy()->addDays(3));
        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) {
            $this->assertSame('Reminder: update your card by Mar 16, 2026', $mail->envelope()->subject);

            return true;
        });

        $this->travelTo($t0->copy()->addDays(6));
        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) {
            $this->assertSame('Your Easy Refer billing pauses on Mar 16, 2026', $mail->envelope()->subject);
            $this->assertStringContainsString(
                'If the charge does not succeed, new signups pause on Mar 16, 2026.',
                $this->renderMailText($mail)
            );

            return true;
        });
    }

    public function test_stage_dates_render_in_business_local_time_for_a_los_angeles_business(): void
    {
        // Grace end Mar 17, 06:30 UTC is Mar 16, 23:30 PDT.
        $this->travelTo(Carbon::parse('2026-03-10 06:30:00'));
        $business = $this->business('America/Los_Angeles');
        $this->record($business);
        $this->failCharge($business);

        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) {
            $this->assertStringContainsString(
                'Your QR code keeps working until Mar 16, 2026.',
                $this->renderMailText($mail)
            );

            return true;
        });
    }

    public function test_stage_dates_for_a_utc_business_match_the_utc_instant(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 01:00:00'));
        $business = $this->business('UTC');
        $this->record($business);
        $this->failCharge($business);

        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) {
            $this->assertStringContainsString(
                'Your QR code keeps working until Mar 17, 2026.',
                $this->renderMailText($mail)
            );

            return true;
        });
    }
}
