<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 onboarding (OB-U1): the flow's one new stored fact. The
 * timestamp records that the owner passed the finish screen
 * (by either door); every step's done-ness stays derived from
 * the live predicates SetupChecklist reads, never stored here.
 * Additive and nullable: existing businesses predate the flow
 * and read as not-yet-completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('checklist_dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
