<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QRK-05 (Train 3): placement codes. A QR code gains the store
 * placement it was printed for (counter, window, table, receipt),
 * so one business can hold one active code per placement and
 * scans can be attributed to the spot that produced them.
 * Additive only: existing rows predate placements and are the
 * store's original counter codes, so the backfill names them
 * 'counter'. The placement stays nullable at the schema level so
 * a row created outside the issuance path can carry null and
 * still resolve (the legacy fallback in Business::activeQrCode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->string('placement', 32)->nullable()->after('label');
            $table->index(['business_id', 'placement', 'state']);
        });

        DB::table('qr_codes')->whereNull('placement')->update(['placement' => 'counter']);
    }

    public function down(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'placement', 'state']);
            $table->dropColumn('placement');
        });
    }
};
