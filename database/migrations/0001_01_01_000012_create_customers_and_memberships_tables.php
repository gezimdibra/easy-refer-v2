<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers (spec Section 7.2): one canonical E.164 phone number
     * is one identity, global across businesses. first_seen_at never
     * changes once set, so delete-and-rejoin cannot reset newness.
     * customer_number_history backs the EC-16 number link: when a
     * customer verifies with a new number, the link is recorded and
     * the cooling window is measured from number_linked_at.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('canonical_email')->nullable()->index();
            $table->timestamp('first_seen_at');
            $table->timestamp('number_linked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_number_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164');
            $table->timestamp('linked_at');
            $table->string('link_reason');
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('share_token_hash')->unique();
            $table->string('share_token_state')->default('active');
            $table->boolean('sms_consent')->default(false);
            $table->string('consent_source')->default('customer_checkbox');
            $table->string('consent_wording_version')->nullable();
            $table->timestamp('consent_updated_at')->nullable();
            $table->unsignedInteger('referral_count')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('customer_number_history');
        Schema::dropIfExists('customers');
    }
};
