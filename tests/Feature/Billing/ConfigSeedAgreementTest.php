<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Config-versus-seed agreement (Phase 4 coverage map row 11): the
 * OD-1 prices live in two places by design, config/billing.php
 * (engine settings) and the seeded plans row (the catalog of
 * record). This pin keeps a pricing edit in one place from
 * silently drifting away from the other, which is a silent money
 * bug class.
 */
class ConfigSeedAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_plan_row_agrees_with_billing_config(): void
    {
        $plan = Plan::launch();

        $this->assertNotNull($plan);
        $this->assertSame('pay_per_visit', $plan->key);
        $this->assertSame('per_referral', $plan->model);
        $this->assertSame(200, (int) config('billing.price_per_verified_visit_cents'));
        $this->assertSame((int) config('billing.price_per_verified_visit_cents'), $plan->per_referral_cents);
        $this->assertSame(9900, (int) config('billing.monthly_cap_cents'));
        $this->assertSame((int) config('billing.monthly_cap_cents'), $plan->monthly_cap_cents);
        $this->assertNull($plan->price_cents);
        $this->assertNull($plan->included_referrals_per_period);
    }

    public function test_signup_credit_and_sms_price_come_from_config(): void
    {
        $this->assertSame(5, (int) config('billing.free_visits_at_signup'));
        $this->assertSame(2, (int) config('billing.sms_segment_price_cents'));
        $this->assertSame('pay_per_visit', config('billing.plan'));
    }

    public function test_plan_seeder_rerun_converges_without_drift_or_duplicates(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->assertSame(2, Plan::count());

        $launch = Plan::launch();
        $this->assertSame(200, $launch->per_referral_cents);
        $this->assertSame(9900, $launch->monthly_cap_cents);

        $unlimited = Plan::query()->where('key', 'unlimited')->firstOrFail();
        $this->assertSame(4900, $unlimited->price_cents);
        $this->assertFalse($unlimited->active);
    }
}
