<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plan catalog (spec Section 7.2, OD-1). The same rows are
 * inserted by the plans migration for fresh installs; this seeder
 * exists so a deploy can re-provision the catalog against an
 * existing database (release 02 runs it against staging). It is
 * idempotent by construction: every row is keyed on `key` with
 * updateOrCreate, so running it any number of times converges to
 * the same two rows and never duplicates or renumbers them. It
 * never touches businesses: the current_plan_id backfill belongs
 * to the migration and to signup, not to a re-run.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['key' => 'pay_per_visit'],
            [
                'name' => 'Pay Per Visit',
                'model' => 'per_referral',
                'price_cents' => null,
                'per_referral_cents' => 200,
                'included_referrals_per_period' => null,
                'monthly_cap_cents' => 9900,
                'square_plan_variation_id' => null,
                'active' => true,
            ],
        );

        Plan::query()->updateOrCreate(
            ['key' => 'unlimited'],
            [
                'name' => 'Unlimited',
                'model' => 'subscription',
                'price_cents' => 4900,
                'per_referral_cents' => null,
                'included_referrals_per_period' => null,
                'monthly_cap_cents' => null,
                'square_plan_variation_id' => null,
                'active' => false,
            ],
        );
    }
}
