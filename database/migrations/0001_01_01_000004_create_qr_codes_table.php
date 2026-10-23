<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business QR codes (spec Section 7.2). The printed code carries an
     * opaque token; only its SHA-256 hash is stored, so the database
     * alone cannot mint a valid code. Exactly one active code per
     * business at launch. No updated_at by design: state changes are
     * revocation events with their own timestamp.
     */
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('state')->default('active')->index();
            $table->string('label')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
