<?php

namespace App\Services\Billing;

use App\Mail\EndProgramMail;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\SmsCampaignRecipient;
use Illuminate\Support\Facades\Mail;

/**
 * FE-CANCEL-01 (design pass sections 1.2-1.3): the final bill
 * at the end of a program. Ending never waits on payment: this
 * service runs AFTER the closure transaction commits, attempts
 * every owed month through the existing charger's per-month
 * path (chargeAllMonths: one record per month, oldest first,
 * free allowance and the $99 cap already inside the settlement
 * walk), writes off a sub-$1 residual so it can never be
 * resurrected, and sends the ONE end confirmation email
 * reporting the outcome's complete legs. A pending
 * (unknown-outcome) record sends nothing: PendingRecordSweeper
 * resolves it and calls notifyResolved() below.
 *
 * Record-only mode (billing.charges_enabled off): the charger
 * would only simulate, so no attempt is made and no paid form
 * is ever rendered; the zero-owed mail stays truthful and is
 * still sent.
 *
 * Idempotency (two-key merge condition): RunFinalBill declares
 * $tries = 3 and queue delivery is at-least-once, so attempt()
 * must converge on the same outcome no matter how many times
 * it runs. It therefore never derives the E-10 outcome from
 * only the records its own invocation created: the
 * business.end_mail_sent audit ledger is the terminal dedupe
 * (an ending whose mail already sent sends nothing again), and
 * any final-bill records a previous invocation left at/after
 * closed_at are re-read at entry and the outcome is derived
 * from them (pending: send nothing, the sweeper owns it;
 * failed: the failed form once; succeeded: the paid form once,
 * including the retry after Mail::send threw post-charge,
 * where no ledger row exists yet).
 *
 * Complete outcome (Amendment 69, the M-3 two-key advisory
 * residual): the ONE E-10 email reports every leg class of the
 * outcome with its own figure — charged, could-not-collect,
 * written off — never only the leg the old branch order happened
 * to reach first, and never a combined figure that hides a leg.
 * Every send path below therefore derives the full legs array
 * (outcomeLegs) from the merged final-bill record set plus the
 * write-off, and sendOutcomeMail() renders it.
 */
class FinalBillService
{
    public function __construct(
        private MonthlyCharger $charger,
        private SettlementService $settlement,
    ) {}

    /**
     * @param  array{owed_cents: int, billable_events: int, text_cents: int}  $snapshot  the tap-time forecast snapshot from the closure audit payload
     * @return list<BillingRecord>
     */
    public function attempt(Business $business, array $snapshot): array
    {
        $business = $business->fresh() ?? $business;
        $owed = (int) ($snapshot['owed_cents'] ?? 0);

        // Terminal dedupe: this ending's E-10 already sent (by
        // an earlier invocation, or by notifyResolved() when a
        // pending record resolved). Exactly one form per ending,
        // however many times the job is delivered.
        if ($this->endMailAlreadySent($business)) {
            return $this->finalBillRecords($business)->all();
        }

        if (! (bool) config('billing.charges_enabled', false)) {
            if ($owed === 0) {
                $this->sendOutcomeMail($business, ['charged' => 0, 'failed' => 0, 'written_off' => 0]);
            }

            return [];
        }

        // Outcome derivation from a previous invocation's
        // records, before charging anything new: a retry must
        // converge on the outcome those records already prove.
        $existing = $this->finalBillRecords($business);

        if ($existing->isNotEmpty()) {
            if ($existing->contains(fn (BillingRecord $r) => $r->state === BillingRecord::STATE_PENDING)) {
                // S2c: the outcome is still unknown; the sweeper
                // resolves it and notifyResolved() sends the form.
                return $existing->all();
            }

            $existingFailed = $existing->filter(fn (BillingRecord $r) => $r->state === BillingRecord::STATE_FAILED);

            if ($existingFailed->isNotEmpty()) {
                // The earlier drain already ended in a definitive
                // failure (and released its events for the monthly
                // retry): the mail leg is all that is owed, once.
                // Re-running the charger here would charge the
                // card a second time inside one ending. The mail
                // still reports the COMPLETE outcome: any months
                // that drain charged before the failure, and any
                // residual it wrote off, ride as their own legs.
                $writtenOff = $this->writeOffResidual($business);

                if ($writtenOff === 0) {
                    $writtenOff = $this->priorWrittenOffCents($business);
                }

                $this->sendOutcomeMail($business, $this->outcomeLegs($existing, $writtenOff));

                return $existing->all();
            }

            // Every existing record succeeded. If a chargeable
            // balance at/above the card minimum remains, the
            // earlier drain was interrupted between months: fall
            // through and let the charger finish it, deriving
            // below from the merged record set. Otherwise the
            // ending is fully charged (a sub-$1 residual is
            // written off exactly as the uninterrupted path
            // would) and the converged outcome — charged legs
            // plus the write-off leg, when there is one — is
            // mailed in full.
            $remaining = (int) $this->settlement->forecastPreview($business)['total_cents'];
            $minimum = (int) config('billing.minimum_charge_cents', 100);

            if ($remaining < $minimum) {
                $writtenOff = 0;

                if ($remaining > 0) {
                    $writtenOff = $this->writeOffResidual($business);
                }

                if ($writtenOff === 0) {
                    $writtenOff = $this->priorWrittenOffCents($business);
                }

                $this->sendOutcomeMail($business, $this->outcomeLegs($existing, $writtenOff));

                return $existing->all();
            }
        }

        $records = $this->charger->chargeAllMonths($business);
        $business = $business->fresh() ?? $business;

        $allRecords = $existing->concat($records)->unique('id')->values();

        if ($allRecords->contains(fn (BillingRecord $r) => $r->state === BillingRecord::STATE_PENDING)) {
            // S2c: the outcome is unknown; the sweeper resolves
            // it and notifyResolved() sends the matching form.
            return $records;
        }

        $writtenOff = $this->writeOffResidual($business);

        if ($writtenOff === 0) {
            // A previous invocation may already have written the
            // residual off and then failed before the mail leg
            // (the write-off is terminal, so this invocation's
            // own writeOffResidual() correctly finds nothing).
            // The audited amount is the converged outcome.
            $writtenOff = $this->priorWrittenOffCents($business);
        }

        // The complete outcome, every leg with its own figure:
        // months the drain charged, months it could not collect,
        // and the sub-$1 residual it wrote off. A mixed outcome
        // mails all of its legs in the one email (Amendment 69);
        // the old branch order mailed only the first leg reached
        // and silently dropped the others.
        $legs = $this->outcomeLegs($allRecords, $writtenOff);

        if ($legs['charged'] === 0 && $legs['failed'] === 0 && $legs['written_off'] === 0) {
            if ($owed === 0) {
                $this->sendOutcomeMail($business, $legs);

                return $records;
            }

            // Nothing was charged and nothing was written off:
            // the usual cause is no card on file. The debt stands
            // and the monthly run keeps trying; the failed leg is
            // the honest one (it names the amount and the retry).
            $legs['failed'] = $owed;
        }

        $this->sendOutcomeMail($business, $legs);

        return $records;
    }

    /**
     * The sub-$1 write-off (GAP 1): after the drain, any
     * remaining unclaimed total under the Square minimum is
     * settled to a terminal non-billable outcome, but ONLY for
     * a closed business. Returns the written-off cents.
     */
    public function writeOffResidual(Business $business): int
    {
        if (! $business->isClosed()) {
            return 0;
        }

        $forecast = $this->settlement->forecastPreview($business);
        $total = (int) $forecast['total_cents'];
        $minimum = (int) config('billing.minimum_charge_cents', 100);

        if ($total <= 0 || $total >= $minimum) {
            return 0;
        }

        $eventIds = BillingEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('billing_record_id')
            ->where('coverage', 'billable')
            ->pluck('id');

        if ($eventIds->isNotEmpty()) {
            BillingEvent::query()->whereIn('id', $eventIds)->update(['coverage' => 'written_off']);
        }

        $smsIds = SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query->where('business_id', $business->id))
            ->whereNull('billing_record_id')
            ->whereNull('written_off_at')
            ->whereNotNull('sms_message_id')
            ->pluck('id');

        if ($smsIds->isNotEmpty()) {
            SmsCampaignRecipient::query()->whereIn('id', $smsIds)->update(['written_off_at' => now()]);
        }

        if ($eventIds->isNotEmpty() || $smsIds->isNotEmpty()) {
            AuditLog::write('billing.final_bill_written_off', null, [
                'amount_cents' => $total,
                'billing_events' => $eventIds->count(),
                'text_rows' => $smsIds->count(),
            ], $business);
        }

        return $total;
    }

    /**
     * Called by PendingRecordSweeper when a pending record that
     * belongs to a closed business's final bill resolves: the
     * E-10 mail sends only now, in the resolved form, and only
     * once per ending (the business.end_mail_sent audit ledger
     * is the dedupe, exactly as in attempt()).
     *
     * The mailed outcome is the COMPLETE one (Amendment 69):
     * this record's resolution is the last unknown, so the legs
     * are derived from the whole final-bill record set — months
     * an earlier drain already charged ride as the charged leg
     * beside this record's own leg — plus the residual write-off,
     * which is settled here because attempt() returned early
     * while this record was pending and never reached it. While
     * any sibling final-bill record is still pending the outcome
     * is not yet complete and nothing sends: the last resolution
     * owns the one mail.
     */
    public function notifyResolved(BillingRecord $record): void
    {
        $business = $record->business;

        if ($business === null || ! $business->isClosed() || $business->closed_at === null) {
            return;
        }

        if ($record->created_at === null || $record->created_at->lt($business->closed_at->copy()->subMinutes(5))) {
            return;
        }

        if (! in_array($record->state, [BillingRecord::STATE_SUCCEEDED, BillingRecord::STATE_FAILED], true)) {
            return;
        }

        if ($this->endMailAlreadySent($business)) {
            return;
        }

        $records = $this->finalBillRecords($business);

        // The pending check covers the SAME admission window the
        // triggering record was admitted under above (created at
        // or after closed_at - 5 minutes), not only the
        // at/after-closed_at set finalBillRecords() returns: a
        // still-pending sibling inside that grace window is part
        // of this ending too, and mailing before it resolves
        // would report a partial outcome — with the general
        // ledger then (correctly) blocking any second mail, its
        // leg would be lost from the one email permanently.
        $anySiblingPending = BillingRecord::query()
            ->where('business_id', $business->id)
            ->where('state', BillingRecord::STATE_PENDING)
            ->where('created_at', '>=', $business->closed_at->copy()->subMinutes(5))
            ->exists();

        if ($anySiblingPending) {
            return;
        }

        // The 5-minute grace above admits a record created just
        // before closed_at, which finalBillRecords() excludes by
        // design (pre-closure records belong to the monthly
        // cycle); its own leg still belongs in the outcome it
        // triggered, so it joins the set for this derivation.
        if (! $records->contains('id', $record->id)) {
            $records->push($record);
        }

        $writtenOff = $this->writeOffResidual($business);

        if ($writtenOff === 0) {
            $writtenOff = $this->priorWrittenOffCents($business);
        }

        $this->sendOutcomeMail($business, $this->outcomeLegs($records, $writtenOff), $record);
    }

    /**
     * The final bill's own records: charge attempts made at or
     * after the ending, in a state that proves an outcome.
     * Records from before closed_at belong to the ordinary
     * monthly cycle (an owner can end while past due) and never
     * derive the E-10 outcome; superseded and simulated records
     * prove nothing and are likewise excluded.
     *
     * @return \Illuminate\Support\Collection<int, BillingRecord>
     */
    private function finalBillRecords(Business $business): \Illuminate\Support\Collection
    {
        if ($business->closed_at === null) {
            return collect();
        }

        return BillingRecord::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $business->closed_at)
            ->whereIn('state', [
                BillingRecord::STATE_PENDING,
                BillingRecord::STATE_SUCCEEDED,
                BillingRecord::STATE_FAILED,
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * The E-10 dedupe ledger, read the way notifyResolved()
     * reads it: a business.end_mail_sent audit row for this
     * business means this ending's one form already went out.
     */
    private function endMailAlreadySent(Business $business): bool
    {
        return AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'business.end_mail_sent')
            ->exists();
    }

    /**
     * The cents a previous invocation wrote off, from its audit
     * row (0 when no write-off ever landed for this ending).
     */
    private function priorWrittenOffCents(Business $business): int
    {
        $audit = AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'billing.final_bill_written_off')
            ->latest('id')
            ->first();

        return (int) ($audit?->metadata['amount_cents'] ?? 0);
    }

    /**
     * The complete outcome of a final bill, leg by leg: what the
     * records prove was charged, what they prove could not be
     * collected, and the residual the write-off settled. Every
     * E-10 send derives its figures here, from the merged record
     * set — never from one branch's local sum.
     *
     * @param  \Illuminate\Support\Collection<int, BillingRecord>  $records
     * @return array{charged: int, failed: int, written_off: int}
     */
    private function outcomeLegs(\Illuminate\Support\Collection $records, int $writtenOffCents): array
    {
        return [
            'charged' => (int) $records
                ->filter(fn (BillingRecord $r) => $r->state === BillingRecord::STATE_SUCCEEDED)
                ->sum('amount_cents'),
            'failed' => (int) $records
                ->filter(fn (BillingRecord $r) => $r->state === BillingRecord::STATE_FAILED)
                ->sum('amount_cents'),
            'written_off' => $writtenOffCents,
        ];
    }

    /**
     * Send the ONE E-10 email for an ending, in the form the
     * complete legs dictate, and write the dedupe ledger row.
     * The ledger's outcome/amount_cents stay the primary leg
     * (failed, then paid, then written_off — the precedence the
     * original branch order used); the three leg figures ride
     * alongside so the mailed outcome is auditable in full.
     *
     * @param  array{charged: int, failed: int, written_off: int}  $legs
     */
    private function sendOutcomeMail(Business $business, array $legs, ?BillingRecord $record = null): void
    {
        $owner = $business->owner;

        if ($owner === null) {
            return;
        }

        [$outcome, $amountCents] = match (true) {
            $legs['failed'] > 0 => ['failed', $legs['failed']],
            $legs['charged'] > 0 => ['paid', $legs['charged']],
            $legs['written_off'] > 0 => ['written_off', $legs['written_off']],
            default => ['zero', 0],
        };

        Mail::to($owner->email)->send(new EndProgramMail(
            $business,
            $outcome,
            $amountCents,
            $legs['charged'],
            $legs['failed'],
            $legs['written_off'],
        ));

        AuditLog::write('business.end_mail_sent', null, [
            'outcome' => $outcome,
            'amount_cents' => $amountCents,
            'charged_cents' => $legs['charged'],
            'failed_cents' => $legs['failed'],
            'written_off_cents' => $legs['written_off'],
            'billing_record_id' => $record?->id,
        ], $business, $record);
    }
}
