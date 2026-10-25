<?php

namespace Tests\Feature\Billing;

use App\Mail\BillingForecastMail;
use App\Mail\CardExpiryGuardMail;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RK-03 (Train 2; copy Amendment 22 BILL-FC-01..03, AC-08):
 * the pre-charge forecast and the card-expiry guard. The
 * forecast line renders from the one settlement preview on
 * the billing page and the dashboard Value panel; a scheduled
 * run emails the forecast inside the last 3 days before the
 * charge (nonzero accrual only) and the expiry guard inside
 * the last 30 days before a card that expires before the next
 * charge — each once per charge cycle, tracked in the audit
 * log. The Action Center carries the same expiry state as a
 * row (AC-08), distinct from SV-02's in-app 60-day pill,
 * which this unit does not touch.
 */
class BillingForecastTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 7100;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function billableBusiness(int $events = 3): Business
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $referrer = World::customer('+12125550989', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        for ($i = 0; $i < $events; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }

        return $business->fresh();
    }

    private function card(Business $business, int $expMonth, int $expYear): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_fc_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_fc_'.$business->id,
        ]);
    }

    public function test_forecast_line_renders_identically_on_billing_and_dashboard(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = $this->billableBusiness(3);
        $owner = World::owner($business);

        $line = 'Counted so far: 3 visits through October 31. Expected charge on November 1: $6.00.';

        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertSee($line);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee($line);
    }

    public function test_no_accrual_no_forecast_line(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $owner = World::owner(World::business(['free_visits_remaining' => 0]));

        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertOk()
            ->assertDontSee('Counted so far:');
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Counted so far:');
    }

    public function test_forecast_email_fires_inside_three_days_before_the_charge_once_per_cycle(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-28 12:00:00');
        $business = $this->billableBusiness(3);
        $owner = World::owner($business);

        $this->artisan('billing:forecast-reminders')->assertExitCode(0);

        Mail::assertSent(BillingForecastMail::class, function (BillingForecastMail $mail) use ($owner) {
            return $mail->hasTo($owner->email)
                && $mail->envelope()->subject === 'Your next Easy Refer charge';
        });
        Mail::assertSentCount(1);

        // Once per cycle: a second run in the same window sends nothing.
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertSentCount(1);
    }

    public function test_forecast_email_does_not_fire_early_or_without_accrual(): void
    {
        Mail::fake();
        $this->billableBusiness(3);
        World::business(['free_visits_remaining' => 0]);

        // Oct 20 is outside the 3 days before the Nov 1 charge.
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertNothingSent();

        // Inside the window, but the zero-accrual business stays silent.
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertSentCount(1);
    }

    public function test_expiry_guard_email_and_action_center_row_for_a_card_expiring_before_the_charge(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['square_customer_id' => 'sq_fc']);
        $this->card($business, 10, 2026);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        // The Action Center row (AC-08).
        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your card expires before your next charge on November 1. Replace it so the charge goes through.')
            ->assertSee('Replace card');

        // The guard email, once per cycle (Oct 25 is inside the
        // 30 days before the Oct 31 expiry).
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertSent(CardExpiryGuardMail::class, function (CardExpiryGuardMail $mail) use ($owner) {
            return $mail->hasTo($owner->email)
                && $mail->envelope()->subject === 'Your card expires before your next charge';
        });
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertSentCount(1);
    }

    public function test_card_expiring_after_the_next_charge_is_silent_everywhere(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['square_customer_id' => 'sq_fc2']);
        $this->card($business, 12, 2026);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('expires before your next charge');

        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertNothingSent();
    }
}
