<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F1 (Amendment 45): the frequency cap's receipt record.
     * Audience exclusions are live computations — correct at
     * compose/confirm, wrong for history — so, following the
     * not_sent_count precedent, the send stamps how many
     * consented members the recency limbs skipped for THIS
     * campaign. No recipient rows are created for them: they
     * were never in the campaign's audience.
     */
    public function up(): void
    {
        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('recent_spacing_skipped_count')->default(0);
            $table->unsignedInteger('recent_cap_skipped_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->dropColumn(['recent_spacing_skipped_count', 'recent_cap_skipped_count']);
        });
    }
};
