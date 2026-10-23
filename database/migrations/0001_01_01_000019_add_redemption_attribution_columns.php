<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redemption attribution (spec Sections 6.1 and 7.2): who
     * completed a redemption or claim, and whether it was
     * attributed to an authenticated session of the same business
     * or completed unattributed at the counter. The referral event
     * log carries the same fact; these columns make the review
     * queue's unattributed surface a query instead of a log scan.
     */
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attribution_state')->nullable()->index();
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('redeemed_by_user_id');
            $table->dropColumn('attribution_state');
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_user_id');
        });
    }
};
