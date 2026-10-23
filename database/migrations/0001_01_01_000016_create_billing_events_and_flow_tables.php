<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing events (spec Section 7.2): exactly one row per verified
     * visit, unique on referral_id, written by the redemption
     * transition (spec Section 3 Step 10). This row is the billing
     * system's only input; the Phase 4 batch engine sweeps rows into
     * billing_records. The coverage column records the Section 9.5
     * disposition the batch computes (billable until the batch marks
     * it covered by the free-visit credit, billed, or over the cap).
     *
     * Flow sessions, redemption nonces, and rate budgets (spec
     * Sections 6.1, 8.4, 10.4): the customer flow session is a
     * server-side record behind its own 30-minute cookie, separate
     * from the owner session; a nonce is bound to one token subject
     * and one page render, single-use, 30-minute life; budgets are
     * database counters keyed by subject hash, never by session.
     */
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('billing_record_id')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('coverage')->default('billable');
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('flow_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->json('data')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('token_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('nonce_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('rate_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('window_started_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_budgets');
        Schema::dropIfExists('token_nonces');
        Schema::dropIfExists('flow_sessions');
        Schema::dropIfExists('billing_events');
    }
};
