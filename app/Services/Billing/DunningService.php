<?php

namespace App\Services\Billing;

use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningRecoveredMail;
use App\Mail\DunningReminderMail;
use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Business;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The dunning run (spec Section 9.6, Amendment 3 / F8; billing
 * UI spec Section 7 step 9): the email half of the past-due
 * policy. The Square webhook handler is the only writer of
 * billing_status past_due and of the billing.past_due audit
 * row; this service reads that audit trail as the episode
 * record and sends the specced emails against it:
 *
 *   day 0  DunningPaymentFailedMail  (DUN-01 / DUN-02)
 *   day 3  DunningReminderMail       (DUN-03 subject / DUN-02)
 *   day 6  DunningPauseWarningMail   (DUN-04 / DUN-02 + DUN-05)
 *   on reactivation  DunningRecoveredMail  (DUN-06 / DUN-07)
 *
 * The grace end is Business::dunningGraceEndsAt() — the ONE
 * derivation the in-app surfaces read (two-key re-review
 * Finding 1, 2026-10-30): the episode start (the
 * billing.past_due audit row) plus
 * config('billing.dunning_grace_days'); the day 6 pause date
 * is that same instant, because enrollment pauses when the
 * grace window closes. An episode the derivation cannot date
 * (no audit row) is undatable here too: nothing is sent and
 * the divergence is logged Critical — this service never
 * invents a date from a failed record, because failed records
 * are not bound to the episode (MonthlyCharger and
 * PendingRecordSweeper write them without ever setting
 * past_due). Every send writes a billing.dunning_email
 * audit row keyed by episode (or reactivation) and stage, and
 * those rows are the idempotency record: a stage is never sent
 * twice for one episode however often the run executes. Stage
 * mail goes only to businesses still past_due; a recovered
 * business hears only the success email. The audience is NOT
 * filtered by business status (F-03, Amendment 36): a closed
 * or disabled business still owes the episode amount and owed
 * months still drain after closure, so it keeps receiving the
 * stage schedule, but the stage mails branch on the status at
 * send time and never claim its code keeps working. The amount comes from
 * the failed record tied to the episode (the audit row's
 * target, else the business's latest failed record); when no
 * record exists anywhere the amount cannot be stated, nothing
 * is sent, and the divergence is logged Critical for
 * reconciliation (spec 9.4's never-silently-dropped rule).
 * No dunning email is ever sent to a customer.
 */
class DunningService
{
    /** The specced contact schedule, in days after past_due entry. */
    private const STAGE_DAYS = [0, 3, 6];

    /**
     * @return array{day0: int, day3: int, day6: int, success: int, skipped: int}
     */
    public function run(): array
    {
        $summary = ['day0' => 0, 'day3' => 0, 'day6' => 0, 'success' => 0, 'skipped' => 0];

        Business::query()
            ->where('billing_status', 'past_due')
            ->orderBy('id')
            ->each(function (Business $business) use (&$summary): void {
                $this->runEpisode($business, $summary);
            });

        $this->runReactivations($summary);

        return $summary;
    }

    /** @param array{day0: int, day3: int, day6: int, success: int, skipped: int} $summary */
    private function runEpisode(Business $business, array &$summary): void
    {
        $episode = AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'billing.past_due')
            ->orderByDesc('id')
            ->first();

        // The ONE derivation, shared with every in-app surface
        // (two-key re-review Finding 1): the grace end comes
        // from Business::dunningGraceEndsAt(), never from a
        // local recomputation or a failed-record anchor. Null
        // is the fail-closed state — past_due without its audit
        // row cannot be dated; the handler writes both in one
        // transaction, so this is a divergence, never a state
        // to invent from — and the episode id below remains the
        // idempotency key, so a null derivation skips the run
        // for this business exactly as the missing row did.
        $graceEnd = $business->dunningGraceEndsAt();

        if ($episode === null || $graceEnd === null) {
            Log::critical('Dunning: business is past_due with no billing.past_due audit row; no episode can be dated.', [
                'business_id' => $business->id,
            ]);
            $summary['skipped']++;

            return;
        }

        $record = $this->episodeRecord($business, $episode);

        if ($record === null) {
            Log::critical('Dunning: past_due episode names no failed record; the amount cannot be stated, so no email is sent.', [
                'business_id' => $business->id,
                'episode_audit_id' => $episode->id,
            ]);
            $summary['skipped']++;

            return;
        }

        $owner = $business->owner;

        if ($owner === null) {
            Log::critical('Dunning: past_due business has no owner to email.', [
                'business_id' => $business->id,
            ]);
            $summary['skipped']++;

            return;
        }

        $now = now();
        // The episode start on the shared derivation's clock:
        // the grace end minus the same config window the
        // derivation added, so stage due-dates and the grace
        // end can never drift apart.
        $episodeStart = $graceEnd->copy()->subDays((int) config('billing.dunning_grace_days', 7));

        // Train A2 (sweep-2 F-05): the grace-end check on the
        // episode clock. A first run delayed past the grace end
        // (scheduler outage, deploy gap) must not send the
        // in-grace stage form carrying a grace date that is
        // already past: every stage due at this run is built
        // with the paused state, and the stage mails render
        // their paused form (the in-app paused line, no grace
        // date anywhere). The amount and the schedule are
        // unchanged; only the form branches.
        $pausedAtSend = $business->isActive() && $now->gte($graceEnd);

        foreach (self::STAGE_DAYS as $day) {
            $stage = 'day'.$day;

            if ($now->lt($episodeStart->copy()->addDays($day))) {
                continue;
            }

            if ($this->alreadySent($business, 'episode_audit_id', $episode->id, $stage)) {
                continue;
            }

            $mail = match ($day) {
                0 => new DunningPaymentFailedMail($business, $record, $graceEnd, $pausedAtSend),
                3 => new DunningReminderMail($business, $record, $graceEnd, $pausedAtSend),
                6 => new DunningPauseWarningMail($business, $record, $graceEnd, $pausedAtSend),
            };

            Mail::to($owner->email)->send($mail);

            AuditLog::write('billing.dunning_email', null, [
                'stage' => $stage,
                'episode_audit_id' => $episode->id,
                'billing_record_id' => $record->id,
                'amount_cents' => $record->amount_cents,
            ], $business, $record);

            $summary[$stage]++;
        }
    }

    /**
     * The failed record an episode is about: the audit row's
     * target when the handler could tie one, else the
     * business's latest definitively failed record (a Square
     * failure for a payment no local record names still sets
     * past_due; the amount then comes from the failed batch
     * the business actually owes on).
     */
    private function episodeRecord(Business $business, AuditLog $episode): ?BillingRecord
    {
        $target = $this->recordTarget($episode);

        if ($target !== null && $target->business_id === $business->id) {
            return $target;
        }

        return BillingRecord::query()
            ->where('business_id', $business->id)
            ->where('state', BillingRecord::STATE_FAILED)
            ->orderByDesc('id')
            ->first();
    }

    /** @param array{day0: int, day3: int, day6: int, success: int, skipped: int} $summary */
    private function runReactivations(array &$summary): void
    {
        AuditLog::query()
            ->where('action', 'billing.reactivated')
            ->orderBy('id')
            ->each(function (AuditLog $reactivation) use (&$summary): void {
                $business = $reactivation->business;

                if ($business === null) {
                    return;
                }

                if ($this->alreadySent($business, 'reactivation_audit_id', $reactivation->id, 'success')) {
                    return;
                }

                $record = $this->recordTarget($reactivation);

                if ($record === null || $record->business_id !== $business->id || $record->state !== BillingRecord::STATE_SUCCEEDED) {
                    Log::critical('Dunning: reactivation names no succeeded record; the success email cannot quote its figures.', [
                        'business_id' => $business->id,
                        'reactivation_audit_id' => $reactivation->id,
                    ]);
                    $summary['skipped']++;

                    return;
                }

                $owner = $business->owner;

                if ($owner === null) {
                    Log::critical('Dunning: reactivated business has no owner to email.', [
                        'business_id' => $business->id,
                    ]);
                    $summary['skipped']++;

                    return;
                }

                Mail::to($owner->email)->send(new DunningRecoveredMail($business, $record));

                AuditLog::write('billing.dunning_email', null, [
                    'stage' => 'success',
                    'reactivation_audit_id' => $reactivation->id,
                    'billing_record_id' => $record->id,
                    'amount_cents' => $record->amount_cents,
                ], $business, $record);

                $summary['success']++;
            });
    }

    private function recordTarget(AuditLog $row): ?BillingRecord
    {
        if ($row->target_id === null || $row->target_type !== (new BillingRecord)->getMorphClass()) {
            return null;
        }

        return BillingRecord::query()->find($row->target_id);
    }

    private function alreadySent(Business $business, string $key, int $auditId, string $stage): bool
    {
        return AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'billing.dunning_email')
            ->where('metadata->stage', $stage)
            ->where('metadata->'.$key, $auditId)
            ->exists();
    }
}
