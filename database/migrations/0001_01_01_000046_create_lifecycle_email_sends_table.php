<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-5 (lifecycle emails design pass, Section 9.2): the send
 * ledger for the five lifecycle emails (drip D1/D2/D3,
 * allowance A1/A2 plus its day-7 reminder form, card-expired
 * C1) and the drip class's own opt-out column.
 *
 * lifecycle_email_sends: one row per (business, send_key)
 * ever claimed. The UNIQUE index on (business_id, send_key)
 * IS the claim (pass Section 4.1): the sender inserts the row
 * first (stamp-then-send, the welcome nudge's discipline
 * generalized from one column to a ledger because this
 * program has seven keys, one of them per-card), re-reads its
 * full predicate against live state, sends synchronously, and
 * deletes the row again if the predicate moved or the
 * transport throws, so a lost race or a dead transport never
 * spends an owner's one send and never double-sends.
 * step_completed_at is the MEASURE stamp (pass Section 9.5):
 * the first later sweep at which the emailed step read done.
 *
 * businesses.setup_reminders_opt_out: the drip turn-off ONLY
 * (pass Section 7.5). It is read by the drip sender and by
 * nothing else; the inert weekly_digest_opt_out / nudge_opt_out
 * columns are NOT this flag and are never read as one.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_email_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('send_key');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('step_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'send_key']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('setup_reminders_opt_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('setup_reminders_opt_out');
        });

        Schema::dropIfExists('lifecycle_email_sends');
    }
};
