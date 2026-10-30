<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M-4 (rewards expiry design pass, Sections 1.3, 1.4, 8.1): the
 * referrer reward's own use-by date. expires_at is stamped at
 * mint from this migration on (available_at + the platform
 * reward_expiry_months, config/referrals.php) and backfilled
 * here for every live row, so every reward already in the
 * system keeps a FULL 12 months of claimable time from its own
 * availability date: nothing is shortened retroactively.
 *
 * Backfill population (pass Section 1.4): rows in
 * pending_vesting or available with a stored available_at.
 * Terminal rows (claimed, denied, expired) keep NULL: they have
 * no claimable future to bound. A live row with NULL
 * available_at (the F-5 legacy shape) keeps NULL expires_at and
 * never time-expires until it has an availability date to anchor
 * to; the mint path stamps both fields together from this build
 * on, so NULL is a legacy population only.
 *
 * expiry_notice_sent_at is the T-30 warning's once-only marker
 * (pass Section 2.4): stamped by SendRewardExpiryWarningSms on a
 * real send or a terminal suppression, never by anything else.
 *
 * Additive only, MariaDB-safe: both columns nullable, the
 * (state, expires_at) index serves the hourly sweeper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expiry_notice_sent_at')->nullable();
            $table->index(['state', 'expires_at']);
        });

        $months = (int) config('referrals.reward_expiry_months', 12);

        DB::table('rewards')
            ->whereIn('state', ['pending_vesting', 'available'])
            ->whereNotNull('available_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($months) {
                foreach ($rows as $row) {
                    DB::table('rewards')
                        ->where('id', $row->id)
                        ->update([
                            'expires_at' => Carbon::parse($row->available_at)
                                ->addMonths($months)
                                ->format('Y-m-d H:i:s'),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropIndex(['state', 'expires_at']);
            $table->dropColumn(['expires_at', 'expiry_notice_sent_at']);
        });
    }
};
