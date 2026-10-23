<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Last consumed TOTP timestep (spec Section 8.6; finding N-1).
     * A verified code records its period counter here, and any code
     * at or before it is refused, so one code can never be replayed
     * inside its drift window (RFC 6238 Section 5.2). The value is a
     * plain counter, not a secret, so the column is unencrypted.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_timestep');
        });
    }
};
