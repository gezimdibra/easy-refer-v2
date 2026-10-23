<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember-me tokens (spec Sections 7.2 and 8.4): selector and
     * validator split. The selector is the lookup key and is safe to
     * store; the validator is the secret and only its SHA-256 hash is
     * stored, so a database read cannot mint a persistent-login cookie.
     * Validators rotate on every use; presenting a superseded validator
     * for a known selector is treated as theft and revokes every token
     * the account holds.
     */
    public function up(): void
    {
        Schema::create('remember_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('selector', 32)->unique();
            $table->string('validator_hash', 64);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remember_tokens');
    }
};
