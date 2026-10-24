<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing records (spec Sections 7.2 and 9.3): every charge
     * attempt and its outcome. A record exists in state `pending`
     * with its deterministic idempotency key BEFORE any Square
     * call, so a retry always reproduces the same key and Square
     * returns the original result instead of double-charging
     * (EC-33). Record-only dry runs are stored as `simulated`,
     * never `pending`, so the sweeper and reconciliation can
     * always tell a dry run from a real charge attempt (spec
     * Section 9.5). The batch sequence table persists the
     * per-business per-day counter the batch key derives from
     * (Section 9.3), incremented atomically.
     */
    public function up(): void
    {
        Schema::create('billing_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('idempotency_key')->unique();
            $table->string('square_payment_id')->nullable();
            $table->string('square_order_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('state')->default('pending');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'state']);
        });

        Schema::create('billing_batch_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('batch_date');
            $table->unsignedInteger('seq')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'batch_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batch_sequences');
        Schema::dropIfExists('billing_records');
    }
};
