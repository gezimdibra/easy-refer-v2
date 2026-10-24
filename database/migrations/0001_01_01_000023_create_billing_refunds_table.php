<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing refunds (spec Section 7.2; Square blueprint Section
     * 1.7 builder note: refunds are first-class rows, not state
     * edits on the funding record). One row per refund against a
     * billing record, created in state `pending` with its
     * deterministic idempotency key before the Refunds API call.
     */
    public function up(): void
    {
        Schema::create('billing_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_record_id')->constrained()->cascadeOnDelete();
            $table->string('square_refund_id')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('state')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refunds');
    }
};
