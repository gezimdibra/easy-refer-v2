<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bulk SMS campaigns (spec Section 7.2, Sections 10.3/10.4).
     * The campaign row is the owner's send and its confirmed
     * figures; the recipient rows are the per-recipient evidence
     * and the metering claim surface: a row is billed by the
     * monthly charger only when its send was attempted (its
     * sms_messages evidence row exists) and no record has claimed
     * it yet. Delivery states extend the spec enum with the two
     * outcomes the send-time re-check and the daily cap produce
     * (skipped, not_sent), recorded with their reason, never
     * silently dropped.
     */
    public function up(): void
    {
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('segment_count')->default(0);
            $table->unsignedInteger('metered_cents')->default(0);
            $table->unsignedInteger('not_sent_count')->default(0);
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('sms_campaigns')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164');
            $table->string('twilio_message_sid')->nullable()->index();
            $table->foreignId('sms_message_id')->nullable()->constrained('sms_messages')->nullOnDelete();
            $table->string('delivery_state')->default('queued')->index();
            $table->string('skip_reason')->nullable();
            $table->string('provider_error_code')->nullable();
            $table->unsignedInteger('segments')->default(0);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->foreignId('billing_record_id')->nullable()->constrained('billing_records')->nullOnDelete()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_campaign_recipients');
        Schema::dropIfExists('sms_campaigns');
    }
};
