<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FE-CANCEL-01 (design pass 2026-10-30, GAP 1): the sub-$1
 * residual at program end is written off. Billing events carry
 * that outcome in their coverage vocabulary ('written_off');
 * campaign text rows have no coverage column, so they carry a
 * written_off_at stamp instead. Every billing text-leg query
 * excludes stamped rows, so a written-off text charge can never
 * be resurrected by a later monthly run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->timestamp('written_off_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('written_off_at');
        });
    }
};
