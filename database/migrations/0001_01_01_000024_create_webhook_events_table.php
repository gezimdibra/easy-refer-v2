<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook events (spec Sections 7.2 and 9.4): the dedupe and
     * replay defense. event_id is unique per provider, so a
     * duplicate delivery inserts nothing and is acknowledged and
     * ignored (EC-27, EC-28). Rows store the event ID, type, and
     * a redacted summary only; full payloads are never retained
     * and no payload file ever lands in the web root (audit H1).
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->string('event_type');
            $table->boolean('signature_verified')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->json('payload_summary')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
