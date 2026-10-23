<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consent evidence, per-message SMS evidence, and the platform
     * opt-out list (spec Section 7.2). Consent events are append-only:
     * a change writes a new row and the membership's current state is
     * the projection of the latest row. sms_opt_outs is checked
     * before every non-verification send to a number, across
     * businesses (EC-22); a STOP is absolute.
     */
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('source');
            $table->string('consent_wording_version')->nullable();
            $table->string('flow');
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_e164');
            $table->string('purpose');
            $table->string('messaging_service')->default('transactional');
            $table->string('template_key');
            $table->text('rendered_body');
            $table->string('twilio_message_sid')->nullable()->index();
            $table->string('delivery_state')->default('queued')->index();
            $table->string('consent_basis')->nullable();
            $table->boolean('opt_out_state_at_send')->default(false);
            $table->timestamps();
        });

        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164')->unique();
            $table->timestamp('opted_out_at');
            $table->string('source');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_opt_outs');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('consent_events');
    }
};
