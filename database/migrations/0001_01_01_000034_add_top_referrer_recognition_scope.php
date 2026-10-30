<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idea Train 2 (Amendment 48; ID-02): the recognition
     * provenance stamps on sms_campaigns. provenance =
     * 'top_referrer' marks a recipient-locked campaign of
     * exactly 1: recipient_membership_id is the only person it
     * may ever send to, standings_month is the business-local
     * YYYY-MM the title belongs to, standings_count /
     * standings_form are the send-time fingerprint the
     * recompute-at-send guard compares against, and
     * body_customized records whether the owner edited the
     * generated body (a customised body is never silently
     * rewritten when the standings move). Every existing
     * campaign keeps null provenance = the pre-train behaviour.
     */
    public function up(): void
    {
        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->string('provenance')->nullable();
            $table->foreignId('recipient_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->string('standings_month')->nullable();
            $table->unsignedInteger('standings_count')->nullable();
            $table->string('standings_form')->nullable();
            $table->boolean('body_customized')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_membership_id');
            $table->dropColumn(['provenance', 'standings_month', 'standings_count', 'standings_form', 'body_customized']);
        });
    }
};
