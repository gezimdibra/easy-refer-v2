<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rewards and the append-only referral event log (spec Section
     * 7.2). A reward snapshots its text at mint time, so later
     * settings edits never rewrite history (EC-35). The vesting
     * lifecycle and the fraud hold are separate axes exactly as on
     * referrals; denied is the terminal resolution of a hold, never a
     * hold itself.
     */
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->text('reward_text_snapshot');
            $table->string('state')->default('pending_vesting')->index();
            $table->boolean('hold')->default(false)->index();
            $table->json('hold_rules')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->timestamp('hold_resolved_at')->nullable();
            $table->foreignId('hold_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('claim_token_hash')->unique();
            // Sealed copy (migration 000018 pattern): the reward-
            // ready SMS and its retries must re-present the claim
            // link after the minting request has ended.
            $table->text('claim_token_encrypted')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('notification_state')->default('queued');
            $table->timestamps();
        });

        Schema::create('referral_events', function (Blueprint $table) {
            $table->id();
            // Nullable: a rejected presentation of an unknown token
            // has no business to attribute (spec Section 6.1 item 5
            // still requires the event).
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reward_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('actor');
            $table->string('ip_address')->nullable();
            $table->string('device_signal_hash')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_events');
        Schema::dropIfExists('rewards');
    }
};
