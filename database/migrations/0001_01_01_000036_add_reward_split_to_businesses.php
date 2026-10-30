<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-REWARD (OR-1, design pass one-reward-design-pass.md §2.2):
 * the reward configuration becomes a stored, auditable choice.
 * reward_split = false is UNIFIED (the default: friend and
 * referrer get the one reward in friend_reward_text, and the
 * referrer column is canonically NULL); true is SPLIT (the
 * Settings opt-in). No values are migrated: existing rows wake
 * up UNIFIED, and any divergent referrer text stays stored
 * until the business's next save canonicalizes it (no real
 * businesses pre-launch; staging fixtures are reseeded
 * deliberately — see the build report).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('reward_split')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('reward_split');
        });
    }
};
