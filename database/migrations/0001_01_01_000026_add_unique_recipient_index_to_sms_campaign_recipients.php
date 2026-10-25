<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defense in depth for the campaign send's exactly-once
     * guarantee (design Section 1.3, review FB-01): the sender
     * serializes racing submits with a row lock and an
     * in-transaction status re-check, and this index makes a
     * duplicate recipient row for one member of one campaign
     * impossible at the storage layer even if a future send path
     * forgets the lock.
     */
    public function up(): void
    {
        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->unique(['campaign_id', 'membership_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'membership_id']);
        });
    }
};
