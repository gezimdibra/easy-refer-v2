<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OD-3's vesting hold becomes a per-business setting (GAP 2,
     * small-gaps design Section 2.2), stored exactly like its
     * sibling redemption_window_days. The default is the config
     * default at ship time (14), so every existing business
     * backfills to the value it already runs and the migration
     * changes no behavior anywhere.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedInteger('reward_vesting_days')->default(14);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('reward_vesting_days');
        });
    }
};
