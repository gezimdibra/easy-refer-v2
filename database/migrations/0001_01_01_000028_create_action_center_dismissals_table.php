<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RK-01 (Train 2): the Action Center's per-item dismissal
     * state. A dismissal is one row per (business, item key)
     * carrying the fingerprint of the state the owner dismissed;
     * the item stays hidden while the live state's fingerprint
     * still matches and resurfaces the moment the state changes
     * (a new hold arrives, a new charge cycle opens). Small and
     * additive: no existing table is touched.
     */
    public function up(): void
    {
        Schema::create('action_center_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');
            $table->string('state_fingerprint');
            $table->timestamps();

            $table->unique(['business_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_center_dismissals');
    }
};
