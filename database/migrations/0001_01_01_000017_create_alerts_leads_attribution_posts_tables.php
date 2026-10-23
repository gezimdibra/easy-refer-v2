<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner alerts, lead capture, attribution event records, and blog
     * posts.
     *
     * business_alerts enforces the first-visit alert's idempotency
     * (spec Section 3 Step 11b): a single alert record per business
     * and kind under a unique key, so retries and replays can never
     * alert twice.
     *
     * leads backs the footer newsletter (spec Section 2.1: a real
     * endpoint with a confirmation state, never a dead form) and the
     * starter kit form (spec Section 2.7, copy LEAD-01); the
     * newsletter's confirmation is a hashed single-use token.
     *
     * attribution_events is the server-side record of the Section 16
     * event map (E1 to E8): each milestone fires once, carries a
     * first-party event ID the platforms deduplicate on, and is
     * delivered through the database queue, never inline.
     */
    public function up(): void
    {
        Schema::create('business_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('notification_state')->default('queued');
            $table->timestamps();

            $table->unique(['business_id', 'kind']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->index();
            $table->string('email')->index();
            $table->string('source')->nullable();
            $table->string('token_hash')->nullable()->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attribution_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_key');
            $table->string('event_name');
            $table->uuid('event_id')->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt');
            $table->longText('body');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('attribution_events');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('business_alerts');
    }
};
