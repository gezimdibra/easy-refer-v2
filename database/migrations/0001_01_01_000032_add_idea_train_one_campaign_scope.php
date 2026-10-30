<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idea Train 1 (Amendment 46; ID-01 Phase 1 + ID-03):
     * - businesses.slow_day_weekday: the OWNER-DECLARED quiet
     *   day (0=Sunday..6=Saturday, business-local), nullable =
     *   no pick. slow_day_reminders_off: the suppression
     *   shutoff state (SD-07), cleared by re-enable or a boost
     *   send.
     * - sms_campaigns.audience_segment: the product's first
     *   audience scope (null = the whole eligible list, exactly
     *   the pre-train behaviour for every existing campaign);
     *   origin: draft provenance (preset / prompt) so prompt
     *   measurement and suppression resets can join; the
     *   win-back cooldown skip stamp follows the F1 precedent;
     *   winback_exempt_campaign_id is the resend own-send
     *   exception's source campaign.
     * - campaign_prompt_events: ONE prompt event log shared by
     *   both features' suppression counters and metrics.
     * - slow_day_analyzer_logs: the dormant section 1.3 floor
     *   computation's record. It surfaces nowhere in this build.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedTinyInteger('slow_day_weekday')->nullable();
            $table->boolean('slow_day_reminders_off')->default(false);
        });

        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->string('audience_segment')->nullable();
            $table->string('origin')->nullable();
            $table->unsignedInteger('winback_cooldown_skipped_count')->default(0);
            $table->unsignedBigInteger('winback_exempt_campaign_id')->nullable();
        });

        Schema::create('campaign_prompt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');
            $table->string('week_key');
            $table->unsignedInteger('week_serial');
            $table->string('event');
            $table->foreignId('campaign_id')->nullable()->constrained('sms_campaigns')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'item_key']);
        });

        Schema::create('slow_day_analyzer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('candidate_weekday')->nullable();
            $table->json('gates')->nullable();
            $table->json('totals')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slow_day_analyzer_logs');
        Schema::dropIfExists('campaign_prompt_events');

        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->dropColumn(['audience_segment', 'origin', 'winback_cooldown_skipped_count', 'winback_exempt_campaign_id']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['slow_day_weekday', 'slow_day_reminders_off']);
        });
    }
};
