<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business columns the referral features read (spec Section 7.2):
     * the value estimate input, the validated Google review link, the
     * first-visit alert's SMS toggle (the settings UI that flips it
     * lands with the Phase 3 settings pages), and the 11 acquisition
     * attribution columns written once at signup from the first-party
     * er_attrib cookie (spec Section 16). First touch wins; the acq_
     * columns are never overwritten and never shown on an
     * owner-facing surface.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedInteger('average_ticket_cents')->nullable();
            $table->string('google_review_url')->nullable();
            $table->boolean('owner_text_notifications')->default(false);

            $table->string('acq_source')->nullable();
            $table->string('acq_medium')->nullable();
            $table->string('acq_campaign')->nullable();
            $table->string('acq_content')->nullable();
            $table->string('acq_term')->nullable();
            $table->string('acq_gclid')->nullable();
            $table->string('acq_fbclid')->nullable();
            $table->string('acq_ttclid')->nullable();
            $table->string('acq_landing_path')->nullable();
            $table->string('acq_referrer_host')->nullable();
            $table->timestamp('acq_captured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'average_ticket_cents',
                'google_review_url',
                'owner_text_notifications',
                'acq_source',
                'acq_medium',
                'acq_campaign',
                'acq_content',
                'acq_term',
                'acq_gclid',
                'acq_fbclid',
                'acq_ttclid',
                'acq_landing_path',
                'acq_referrer_host',
                'acq_captured_at',
            ]);
        });
    }
};
