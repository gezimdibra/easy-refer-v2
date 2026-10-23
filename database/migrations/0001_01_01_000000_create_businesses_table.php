<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Businesses (spec Section 7.2). Internal integer keys only; nothing
     * in this table is ever addressed by ID from outside (spec Section
     * 7.1). Billing and reward configuration columns land with the table
     * now; the plans foreign key arrives with the plans table in Phase 4.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->index();
            $table->string('contact_email');
            $table->string('contact_phone_e164');
            $table->string('country');
            $table->string('street');
            $table->string('city');
            $table->string('state');
            $table->string('postal_code');
            $table->string('timezone')->default('America/New_York');
            $table->string('status')->default('active')->index();
            $table->text('friend_reward_text')->nullable();
            $table->text('referrer_reward_text')->nullable();
            $table->unsignedInteger('redemption_window_days')->default(30);
            $table->unsignedInteger('daily_referral_cap')->default(5);
            $table->unsignedInteger('monthly_referral_cap')->default(50);
            $table->string('square_customer_id')->nullable()->index();
            $table->string('billing_status')->default('none');
            $table->unsignedBigInteger('current_plan_id')->nullable();
            $table->unsignedInteger('free_visits_remaining')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
