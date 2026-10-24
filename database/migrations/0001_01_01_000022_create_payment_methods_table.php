<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment methods (spec Section 7.2, Square blueprint Section
     * 1.1): the local mirror of a card on file. Only brand, last4,
     * and expiry are ever stored; the card number and the
     * single-use token never persist. The row is inserted in state
     * `pending` with its deterministic idempotency key before the
     * CreateCard call, mirroring the billing_records discipline
     * (spec Section 9.3). Exactly one succeeded row per business
     * is the default; billing always charges the default.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('square_card_id')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('state')->default('pending');
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'state', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
