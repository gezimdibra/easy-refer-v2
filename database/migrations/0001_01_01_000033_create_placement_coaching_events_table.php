<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ID-04 placement coaching (design pass Sections 5.3, 7.1):
     * the feature's own minimal record. One row per impression
     * (one per business per fingerprint per day the card renders)
     * and one row per dismissal, so the acted-within-14-days
     * derivation, the dismissal streak behind the 90-day snooze,
     * and the 7-day global cooldown are all computable from
     * data. Scan counts themselves get NO new storage: they are
     * read from the shipped scan events on every render.
     * Numbered 000033 because 000031 and 000032 are taken by the
     * Train A4/F1 and Idea Train 1 migrations that land before
     * this train merges.
     */
    public function up(): void
    {
        Schema::create('placement_coaching_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('fingerprint');
            $table->string('worst_placement');
            $table->string('best_placement');
            $table->string('move_key');
            $table->unsignedInteger('gap');
            $table->unsignedInteger('best_scans');
            $table->unsignedInteger('worst_scans');
            $table->unsignedInteger('window_days');
            $table->timestamps();

            $table->index(['business_id', 'kind', 'created_at']);
            $table->index(['business_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_coaching_events');
    }
};
