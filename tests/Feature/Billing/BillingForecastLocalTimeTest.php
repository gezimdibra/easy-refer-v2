<?php

namespace Tests\Feature\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Support\BillingForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train 3.5 (retro 2026-10-25, business-logic audit Section 4
 * seam 1): the monthly charge fires on the 1st at 03:00 UTC
 * (routes/console.php), which is the owner's last evening of
 * the prior month in every US timezone. The forecast line and
 * the forecast email must name the charge date the owner will
 * actually experience, the business-local date of that instant
 * via Business::localTime(), while the billing month itself
 * stays UTC-anchored exactly as decided.
 */
class BillingForecastLocalTimeTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 7300;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function billableBusiness(string $timezone, int $events = 3): Business
    {
        $business = World::business(['free_visits_remaining' => 0, 'timezone' => $timezone]);
        // Train A2: the forecast names a charge date only when
        // a charge is really expected (a default card on file);
        // the fixture carries one, as a live business would.
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_lt_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_lt_'.$business->id,
        ]);
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

    /**
     * The charge instant (Nov 1, 03:00 UTC) lands on Oct 31
     * local in every US zone, from Eastern to Hawaii.
     *
     * @return array<string, array{0: string}>
     */
    public static function usTimezones(): array
    {
        return [
            'Eastern' => ['America/New_York'],
            'Central' => ['America/Chicago'],
            'Mountain' => ['America/Denver'],
            'Mountain no DST' => ['America/Phoenix'],
            'Pacific' => ['America/Los_Angeles'],
            'Alaska' => ['America/Anchorage'],
            'Hawaii' => ['Pacific/Honolulu'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('usTimezones')]
    public function test_forecast_line_names_the_local_charge_date_in_us_zones(string $timezone): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = $this->billableBusiness($timezone);

        $this->assertSame(
            'Counted so far: 3 visits through October 31. Expected charge on October 31: $6.00.',
            BillingForecast::line($business),
            "Forecast line for {$timezone} must name the local charge date (Oct 31), not the UTC date (Nov 1)."
        );
    }

    public function test_forecast_line_for_a_utc_business_keeps_the_first(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = $this->billableBusiness('UTC');

        $this->assertSame(
            'Counted so far: 3 visits through October 31. Expected charge on November 1: $6.00.',
            BillingForecast::line($business)
        );
    }

    public function test_forecast_line_names_the_local_charge_date_across_the_year_boundary(): void
    {
        Carbon::setTestNow('2026-12-15 12:00:00');
        $business = $this->billableBusiness('America/New_York');

        // The Jan 1, 03:00 UTC charge is Dec 31, 22:00 in New York.
        $this->assertSame(
            'Counted so far: 3 visits through December 31. Expected charge on December 31: $6.00.',
            BillingForecast::line($business)
        );
    }

    public function test_forecast_email_body_names_the_local_charge_date(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00');
        $business = $this->billableBusiness('America/Los_Angeles');

        $body = BillingForecast::emailBody($business);

        $this->assertNotNull($body);
        $this->assertStringContainsString('Your next monthly charge runs on October 31.', $body);
        $this->assertStringContainsString('Counted so far: 3 visits through October 31', $body);
        $this->assertStringNotContainsString('November 1', $body);
    }

    public function test_next_charge_date_for_returns_the_local_instant_of_the_scheduled_charge(): void
    {
        $at = Carbon::parse('2026-10-25 12:00:00');
        $business = $this->billableBusiness('America/Chicago');

        $local = BillingForecast::nextChargeDateFor($business, $at);

        // Nov 1, 03:00 UTC is Oct 31, 22:00 CDT in Chicago.
        $this->assertSame('2026-10-31 22:00', $local->format('Y-m-d H:i'));
        $this->assertSame('America/Chicago', $local->tzName);
        // The underlying schedule date stays the UTC 1st.
        $this->assertSame('2026-11-01', BillingForecast::nextChargeDate($at)->format('Y-m-d'));
        $this->assertSame('2026-11-01 03:00', BillingForecast::nextChargeInstant($at)->format('Y-m-d H:i'));
    }

    public function test_forecast_line_renders_the_local_date_on_the_billing_page(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = $this->billableBusiness('America/Los_Angeles');
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertOk()
            ->assertSee('Counted so far: 3 visits through October 31. Expected charge on October 31: $6.00.')
            ->assertDontSee('Expected charge on November 1');
    }
}
