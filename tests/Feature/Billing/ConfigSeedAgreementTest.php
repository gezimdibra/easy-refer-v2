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
        // Every price figure the billing page's plan block renders
        // agrees with config: the per-visit figure resolves from
        // this row to the configured per-verified-visit price, and
        // the cap line is the configured monthly cap.
        $this->assertSame((int) config('billing.price_per_verified_visit_cents'), $plan->perVisitPriceCents());
        $this->assertSame((int) config('billing.monthly_cap_cents'), $plan->monthly_cap_cents);
    }

    public function test_per_visit_price_resolves_by_plan_model_for_both_rows(): void
    {
        // price_cents is the subscription slot and per_referral_cents
        // the per-visit slot; each row fills only its own. The
        // unlimited row carries its period price in price_cents and
        // has no per-visit price; the launch row is the mirror.
        $unlimited = Plan::query()->where('key', 'unlimited')->firstOrFail();
        $this->assertSame('subscription', $unlimited->model);
        $this->assertSame(4900, $unlimited->price_cents);
        $this->assertNull($unlimited->per_referral_cents);
        $this->assertNull($unlimited->perVisitPriceCents());
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
