<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 columns (spec Sections 5.5 and 5.8): the two remaining
 * notification toggles of Section 5.5 (the weekly digest opt-out and
 * the nudge opt-out; the owner text toggle shipped in migration
 * 000011), the per-business onboarding checklist dismissal of
 * Section 5.8, the closure timestamp of the Section 5.5 account
 * closure flow, and the membership removal marker of Section 5.2
 * (removing a customer from a store ends the connection without
 * deleting history, so it is a timestamp, not a delete: the
 * referrals table cascades on membership deletion and the history
 * must survive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('weekly_digest_opt_out')->default(false);
            $table->boolean('nudge_opt_out')->default(false);
            $table->timestamp('checklist_dismissed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['weekly_digest_opt_out', 'nudge_opt_out', 'checklist_dismissed_at', 'closed_at']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
