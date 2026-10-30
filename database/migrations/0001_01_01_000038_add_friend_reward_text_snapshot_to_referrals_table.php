<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-UP-1 (truth sweep 2026-10-29 PM, Known Item 1): the friend's
 * reward text, snapshotted at CLAIM CREATION onto the referral.
 * The referrer's side has snapshotted since 000014
 * (rewards.reward_text_snapshot, minted at redemption); the
 * friend's side never stored its promised text anywhere, so
 * every historical rendering read live config and an owner's
 * later edit rewrote the record of past visits.
 *
 * NULL is load-bearing: it means no snapshot was saved for this
 * referral (every row that predates this migration, and any row
 * minted outside the claim flow). Readers fall back to live
 * config for NULL rows: the LEGACY LIVE-CONFIG FALLBACK, named
 * in Referral::promisedFriendRewardText(). NO data migration in
 * either direction: up() writes no row's value, because the
 * claim-time text of a pre-existing row is unknowable and
 * backfilling today's text would manufacture history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->text('friend_reward_text_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropColumn('friend_reward_text_snapshot');
        });
    }
};
