<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans (spec Section 7.2). The catalog lives in the database and
     * is seeded by this migration, edited only by platform admin
     * tooling, never by request data. Marketing and billing render
     * every amount from these rows (spec Sections 2.1, 9.2), so the
     * table lands with the Phase 2 marketing surfaces rather than
     * waiting for the Phase 4 engine that consumes it.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('model');
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedInteger('per_referral_cents')->nullable();
            $table->unsignedInteger('included_referrals_per_period')->nullable();
            $table->unsignedInteger('monthly_cap_cents')->nullable();
            $table->string('square_plan_variation_id')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        // Launch seed rows (OD-1, decided 2026-10-23). The cents cap
        // is the rule: at 200 cents per visit at most 49 visits bill
        // in a month (9800 of the 9900 cap).
        DB::table('plans')->insert([
            [
                'key' => 'pay_per_visit',
                'name' => 'Pay Per Visit',
                'model' => 'per_referral',
                'price_cents' => null,
                'per_referral_cents' => 200,
                'included_referrals_per_period' => null,
                'monthly_cap_cents' => 9900,
                'square_plan_variation_id' => null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'unlimited',
                'name' => 'Unlimited',
                'model' => 'subscription',
                'price_cents' => 4900,
                'per_referral_cents' => null,
                'included_referrals_per_period' => null,
                'monthly_cap_cents' => null,
                'square_plan_variation_id' => null,
                'active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $launchPlanId = DB::table('plans')->where('key', 'pay_per_visit')->value('id');

        // Every business is created on the launch plan (spec Section
        // 9.9); businesses created before this table existed are
        // backfilled to the same plan.
        DB::table('businesses')->whereNull('current_plan_id')->update(['current_plan_id' => $launchPlanId]);

        Schema::table('businesses', function (Blueprint $table) use ($launchPlanId) {
            $table->foreign('current_plan_id')->references('id')->on('plans');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['current_plan_id']);
        });

        Schema::dropIfExists('plans');
    }
};
