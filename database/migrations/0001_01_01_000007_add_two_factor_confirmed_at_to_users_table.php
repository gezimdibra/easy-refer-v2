<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-factor confirmation timestamp (spec Section 8.6). The
     * secret and recovery code columns landed with the users table in
     * Phase 1 as schema readiness; this column separates a pending
     * enrollment (secret stored, never confirmed) from an active one,
     * which is the state the challenge middleware enforces.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_confirmed_at');
        });
    }
};
