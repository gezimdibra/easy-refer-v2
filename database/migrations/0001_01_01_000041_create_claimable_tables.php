<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1 CLAIMABLE PRE-MADE SHOPS (g1-claimable-design-pass.md §5, §8, §9).
 *
 * - claimable_pages: the register row for one pre-made shop page.
 *   The snapshot holds ONLY the §1 public-listing fields (name,
 *   address, type, listed phone, hours, one photo ref, fetch date).
 *   The public token is stored hashed (OpaqueToken), never plain.
 *   Snapshot fields are purged at expiry (P7) and at removal purge
 *   (R2); the register row itself is retained for the §9 metrics
 *   and the §8 suppression promises.
 * - claimable_events: the §9.2 funnel, per page id, counts and
 *   timestamps only — no visitor identity, no customer data.
 * - claim_reviews: the §4 operator-review queue (gate fallback and
 *   contested claims). No account is ever created by a review row.
 * - claim_suppressions: the §8.5 list, keyed by place_id only.
 * - businesses.claim_source / claimable_page_id: the §3.2 claim
 *   attribution stored on the business at the checkpoint. These are
 *   attribution columns only; no money, reward, or activation code
 *   reads them (the claimed business lands in the standard gate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claimable_pages', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('place_id')->index();
            $table->string('cohort')->default('concierge');
            // Snapshot (purged at P7/R2): public listing facts only.
            $table->string('shop_name')->nullable();
            $table->string('business_type')->nullable();
            $table->string('trade_label')->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('listed_phone_e164')->nullable();
            $table->json('opening_hours')->nullable();
            $table->string('photo_ref')->nullable();
            $table->string('starter_reward_text')->nullable();
            $table->timestamp('snapshot_fetched_at')->nullable();
            // Lifecycle (§5 P-states; §8 R-states derive from the
            // removal columns below).
            $table->string('status')->default('generated')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('claimed_business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_source')->nullable();
            $table->string('status_at_removal')->nullable();
            $table->boolean('claim_in_flight_at_removal')->default(false);
            $table->timestamp('purged_at')->nullable();
            $table->unsignedInteger('removal_count')->default(0);
            $table->boolean('operator_review_flag')->default(false);
            $table->string('kill_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('claimable_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claimable_page_id')->constrained('claimable_pages')->cascadeOnDelete();
            $table->string('event')->index();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('claim_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claimable_page_id')->constrained('claimable_pages')->cascadeOnDelete();
            $table->string('kind')->index(); // gate_fallback | contested
            $table->string('requester_name');
            $table->string('contact_phone_e164')->nullable();
            $table->string('identity_hash', 64)->index();
            $table->string('status')->default('pending')->index(); // pending|approved|denied|closed_removed|expired
            $table->string('handoff_token_hash', 64)->nullable();
            $table->timestamp('handoff_expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('claim_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('place_id')->unique();
            $table->string('shop_name')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('suppression_ends_at')->nullable()->index();
            $table->unsignedInteger('removal_count')->default(1);
            $table->boolean('operator_review_flag')->default(false);
            $table->timestamps();
        });

        Schema::create('claim_quiet_list', function (Blueprint $table) {
            $table->id();
            $table->string('place_id')->unique();
            $table->string('reason')->default('two_expiries');
            $table->timestamp('quiet_until')->nullable()->index();
            $table->timestamps();
        });

        // The claim gate's rolling-24h budget ledger (§4 +
        // reconciliation ruling): one timestamped row per gate
        // hit (send, wrong entry, lock), counted over the trailing
        // window by ClaimGateService. Scoped to the claim gate
        // only; the shared rate_budgets fixed windows other
        // surfaces use are untouched.
        Schema::create('claim_gate_hits', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('claim_source')->nullable()->index();
            $table->foreignId('claimable_page_id')->nullable()->constrained('claimable_pages')->nullOnDelete();
            // §5/P3 backstop (review item 1a): at most ONE business
            // may claim a page, enforced at the schema level beneath
            // the checkpoint transaction's in-transaction re-check.
            $table->unique('claimable_page_id');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['claimable_page_id']);
            $table->dropConstrainedForeignId('claimable_page_id');
            $table->dropColumn('claim_source');
        });
        Schema::dropIfExists('claim_gate_hits');
        Schema::dropIfExists('claim_quiet_list');
        Schema::dropIfExists('claim_suppressions');
        Schema::dropIfExists('claim_reviews');
        Schema::dropIfExists('claimable_events');
        Schema::dropIfExists('claimable_pages');
    }
};
