<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ID-06 second-code prompt (Amendment 49; design pass
     * Sections 5, 8.1): the feature's minimal prompt-state
     * record — one row per business, ever — plus its shown
     * stamps. Detection itself is a computed read over the
     * shipped settled predicate, print records, and scan
     * events (no new storage for any of those); these tables
     * hold only what cannot be derived: the terminal outcome
     * (acted / dismissed / satisfied / gap-closed / expired),
     * the stamped settlement instant and offered placement
     * the readout attributes against, and one impression row
     * per business per business-local day the row rendered.
     *
     * Numbered 000035 because 000033 (ID-04 placement
     * coaching) and 000034 (ID-02/ID-05 recognition, renumbered
     * at its merge) land before this train merges; if the
     * merge order changes, renumber mechanically — nothing in
     * code references the number.
     */
    public function up(): void
    {
        Schema::create('second_placement_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->string('state');
            $table->timestamp('settled_at')->nullable();
            $table->string('offered_placement')->nullable();
            $table->string('acted_placement')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'state']);
        });

        Schema::create('second_placement_prompt_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->string('offered_placement')->nullable();
            $table->date('shown_on');
            $table->timestamps();

            $table->unique(['business_id', 'shown_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('second_placement_prompt_impressions');
        Schema::dropIfExists('second_placement_prompts');
    }
};
