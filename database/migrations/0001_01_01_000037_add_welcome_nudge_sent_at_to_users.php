<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SINGLE SIGNUP EMAIL train (owner amendment, 2026-10-29):
 * the WelcomeMail's once-only sent marker. The unverified
 * nudge fires exactly once per account; the stamp lives on
 * the user row itself (the column-stamp pattern the reward
 * and campaign sends use: notification_state / sent_at), and
 * WelcomeNudgeService claims it with a conditional update
 * (whereNull) BEFORE sending, so two overlapping scheduler
 * runs can never both send: the once-only rule is structural,
 * not a check-then-send race. NULL means never nudged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_nudge_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('welcome_nudge_sent_at');
        });
    }
};
