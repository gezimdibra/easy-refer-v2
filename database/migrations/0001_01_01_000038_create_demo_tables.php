<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G6 DEMO PHASE 1 (Amendment 63): the demo business flag, the
 * demo claim channel stamp, and the demo's own session/event/
 * attribution stores.
 *
 * - businesses.is_demo: the ONE key every demo exclusion reads
 *   (demo-phase1-design-pass.md Section 13). Name and id matching
 *   are never used for demo hygiene.
 * - referrals.source: the claim's channel stamp. Existing rows
 *   are customer referrals ('referral'); the demo claim carries
 *   'demo' and no referrer, so referrer_membership_id becomes
 *   nullable. A demo referral can never bill: redemption settles
 *   it coverage='demo' (BillingEvent), excluded by coverage AND
 *   by the business flag, either check alone sufficient.
 * - demo_sessions: the server-side demo session store. The typed
 *   phone lives ONLY here (design pass Section 5.2), keyed by the
 *   hash of the session cookie token, deleted at expiry,
 *   completion exit, restart, or reseed sweep.
 * - demo_events: the demo's own instrumentation (Section 15.1).
 *   Payloads carry the opaque session id and step names only:
 *   no phone, no shop name, no free text, ever.
 * - demo_attributions: the signup carryover record (Section 8
 *   C-1): channel, entry point, completion state, trade chip,
 *   timestamps. No phone, no shop name, no demo activity.
 * - demo_reseed_logs: the nightly reseed's own ledger; two
 *   consecutive failures take the demo entry points down
 *   (Section 11.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->string('source')->default('referral')->index();
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('referrer_membership_id')->nullable()->change();
        });

        Schema::create('demo_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->json('data')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('demo_events', function (Blueprint $table) {
            $table->id();
            $table->string('session_key')->index();
            $table->string('event')->index();
            $table->string('step')->nullable();
            $table->string('at_step')->nullable();
            $table->string('entry_point')->nullable();
            $table->string('channel')->default('embedded');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('demo_attributions', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash')->unique();
            $table->string('channel')->default('embedded');
            $table->string('entry_point')->nullable();
            $table->string('completion_state')->default('started');
            $table->string('at_step')->nullable();
            $table->string('trade_chip')->nullable();
            $table->boolean('demo_completed')->default(false);
            $table->foreignId('signup_business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('demo_reseed_logs', function (Blueprint $table) {
            $table->id();
            $table->boolean('succeeded');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_reseed_logs');
        Schema::dropIfExists('demo_attributions');
        Schema::dropIfExists('demo_events');
        Schema::dropIfExists('demo_sessions');

        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('referrer_membership_id')->nullable(false)->change();
            $table->dropColumn('source');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
