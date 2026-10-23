<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referrals (spec Section 7.2). The lifecycle state and the
     * review hold are separate columns by design: a hold never
     * rewrites state. Dedupe is a database guarantee, not application
     * logic: the generated column active_pair_key holds the
     * (business, friend) pair only while the referral is
     * pending_redemption and NULL in every other state, and its
     * unique index therefore admits at most one live referral per
     * pair (NULLs never conflict).
     */
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referrer_membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('referred_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('redeem_token_hash')->unique();
            // Sealed copy (migration 000018 pattern): dedupe returns
            // the friend to their existing redemption page, which
            // requires re-presenting the link; a database read still
            // yields no usable link.
            $table->text('redeem_token_encrypted')->nullable();
            $table->string('state')->default('pending_redemption')->index();
            $table->boolean('hold')->default(false)->index();
            $table->json('hold_rules')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->timestamp('hold_resolved_at')->nullable();
            $table->foreignId('hold_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('risk_score')->default(0);
            $table->json('risk_rules')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "CASE WHEN state = 'pending_redemption' THEN business_id || ':' || referred_customer_id ELSE NULL END"
            : "CASE WHEN state = 'pending_redemption' THEN CONCAT(business_id, ':', referred_customer_id) ELSE NULL END";

        DB::statement("ALTER TABLE referrals ADD COLUMN active_pair_key VARCHAR(64) GENERATED ALWAYS AS ({$expression}) VIRTUAL");

        Schema::table('referrals', function (Blueprint $table) {
            $table->unique('active_pair_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
