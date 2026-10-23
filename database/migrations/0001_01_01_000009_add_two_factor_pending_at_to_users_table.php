<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the pending two-factor enrollment was started (spec
     * Section 8.6; finding N-2). Confirmation is refused once the
     * pending secret is older than the configured window
     * (security.two_factor.pending_ttl_seconds), and the stale
     * secret is cleared, so an unfinished enrollment cannot wait
     * indefinitely to be completed by whoever holds the session.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_pending_at')->nullable()->after('two_factor_last_timestep');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_pending_at');
        });
    }
};
