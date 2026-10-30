<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SK-6 "how did you hear" capture (growth-marketing-plan.md
 * Section 4 queue item 3 + Section 6 gap SK-6): the ONE
 * self-reported signup source, stored once at the business
 * level. Before this migration the only signup channels on the
 * row were machine stamps: the acq_ UTM columns (migration
 * 000011, first-touch ad attribution, never surfaced) and
 * businesses.claim_source (migration 000041, the G1 claim
 * channel only). Neither can answer the plan's Friday readout:
 * the G3 decal metric, the G1 claim-rate readouts, and every
 * channel kill decision read the EFFECTIVE channel, resolved
 * in exactly one place (App\Support\SignupSource):
 *
 * PRECEDENCE: a claimed business's claim_source is the
 * authoritative channel. The self-reported columns are never
 * written for a claimed business, and the resolver prefers
 * claim_source whenever it is set, so the field can never
 * overwrite or contradict the claim record.
 *
 * The capture is OPTIONAL end to end: every column is
 * nullable, a skipped question stores NULL, and no money,
 * reward, activation, or ranking path reads these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('signup_source')->nullable()->index();
            $table->string('signup_source_detail')->nullable();
            $table->timestamp('signup_source_captured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'signup_source',
                'signup_source_detail',
                'signup_source_captured_at',
            ]);
        });
    }
};
