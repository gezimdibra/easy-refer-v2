<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ID-11 direct coupons (S3, Amendment 53; design pass
     * Sections 5.1 and 5.3): the batch (the placement identity
     * for source=coupon) and the claim (the single-use token
     * the counter redeems). Counts are DERIVED from scan events
     * and claim rows at read time, so no counter column exists
     * for scans, claims, or redemptions. The claim table carries
     * NO referrer columns, NO billing columns, NO hold columns,
     * and NO vesting columns: those absences are the design
     * (pass Sections 2.3 and 4.4), and adding any of them
     * re-opens the pass, not just a migration.
     *
     * Numbered 000044, the next free number at this train's
     * base (main 5adc0b3, whose last migration is 000043);
     * nothing in code references the number. Explicit
     * timestamp columns are declared nullable per the
     * MariaDB guard (MigrationTimestampGuardTest); every
     * expires_at is always written by its create path. The hint
     * dismissal table holds the one piece of
     * per-owner state the benefit hint needs (pass Section 6.2:
     * shows until first batch or dismissal, then quiet for
     * good); the first-batch leg is derived, not stored.
     */
    public function up(): void
    {
        Schema::create('coupon_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label');
            $table->string('offer_text');
            $table->unsignedInteger('claim_cap');
            $table->string('token_hash')->unique();
            $table->text('token_encrypted')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->timestamp('flag_cleared_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('coupon_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('coupon_batches')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('coupon');
            $table->string('state')->default('ready');
            $table->string('offer_text');
            $table->string('redeem_token_hash')->unique();
            $table->text('redeem_token_encrypted')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'created_at']);
            $table->index(['batch_id', 'customer_id']);
            $table->index(['batch_id', 'state']);
        });

        Schema::create('coupon_hint_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_hint_dismissals');
        Schema::dropIfExists('coupon_claims');
        Schema::dropIfExists('coupon_batches');
    }
};
