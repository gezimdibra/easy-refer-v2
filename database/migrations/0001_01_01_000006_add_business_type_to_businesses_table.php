<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business type (spec Sections 4.1 and 7.2, Amendment 3 item
     * A3-07): collected optionally at signup Step 1, editable later in
     * settings. It drives the Section 5.5 reward starter packs, the
     * Section 5.9 average-ticket prefill, and the Section 2.6 vertical
     * content defaults, so the column belongs to the Phase 1 schema
     * even though its consumers land in later phases (finding FB-02).
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('business_type')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('business_type');
        });
    }
};
