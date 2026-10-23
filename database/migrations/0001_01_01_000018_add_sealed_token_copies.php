<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sealed token copies (spec Section 7.1 tension, resolved in the
     * Sprint 2 build report): share and QR tokens must be
     * re-presentable by the server (the share page after a returning
     * customer's re-verification, spec Section 3 Step 5; the owner's
     * QR page and posters, spec Section 5.4), which hash-only storage
     * cannot do. These columns hold the token encrypted under the app
     * key alongside the SHA-256 lookup hash, so a database read still
     * yields no usable links (the Section 7.1 property). Redeem and
     * claim tokens carry sealed copies of their own for the same
     * reason (migrations 000013 and 000014): the reward-ready text
     * and receipt re-presentation need the plaintext after the fact,
     * while token LOOKUP stays hash-only everywhere.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->text('share_token_encrypted')->nullable();
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            $table->text('token_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('share_token_encrypted');
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropColumn('token_encrypted');
        });
    }
};
