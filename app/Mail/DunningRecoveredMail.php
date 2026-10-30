<?php

namespace App\Mail;

use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\SmsCampaignRecipient;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Dunning success (spec Section 9.6; inventory DUN-06 subject,
 * DUN-07 body): sent by the dunning run when a past_due
 * business's charge has succeeded and the webhook handler has
 * reactivated it. The figures are the recovered record's own:
 * the count of its billable coverage events, and the value
 * estimate computed exactly as the per-charge statement page
 * computes it (VisitValuation for the record's period), so
 * the email and the statement can never disagree.
 *
 * Train A2 (sweep-2 F-06): the body branches on the record's
 * composition. A campaign-only record is a text charge, and
 * the visits sentence about it ("covered 0 visits that
 * counted, worth about $0") was false; a mixed record names
 * both legs. The estimate is scoped to the record's OWN
 * billable visits (count x the business's typical spend per visit, the
 * same per-visit basis VisitValuation uses), never the whole
 * period month, whose free and over-cap visits this charge
 * did not cover.
 */
class DunningRecoveredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public BillingRecord $record,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Easy Refer payment went through');
    }

    public function content(): Content
    {
        $visits = $this->record->events()->where('coverage', 'billable')->count();
        $textCents = (int) SmsCampaignRecipient::query()
            ->where('billing_record_id', $this->record->id)
            ->sum('amount_cents');
        $estimatedValue = Money::usd($visits * (int) $this->business->average_ticket_cents);
        $textAmount = Money::usd($textCents);

        // P1 SPEND-DEFAULT (critic report 2026-10-29): the
        // estimated-sales clause and the Estimated value row
        // are computed from the owner's typical spend per
        // visit; when the owner never set one there is no
        // estimate to state, so both are omitted rather than
        // printing a $0 figure on a basis that does not exist.
        $hasSpend = $this->business->hasTypicalSpend();

        $bodyLine = match (true) {
            $visits > 0 && $textCents > 0 && $hasSpend => 'This charge covered '.$visits.' visits that counted, worth about '
                .$estimatedValue.' in estimated sales, based on your typical spend per visit (estimate), plus '.$textAmount.' in text charges.',
            $visits > 0 && $textCents > 0 => 'This charge covered '.$visits.' visits that counted, plus '.$textAmount.' in text charges.',
            $textCents > 0 => 'This charge covered your text campaigns, for '.$textAmount.' in text charges.',
            $hasSpend => 'This charge covered '.$visits.' visits that counted, worth about '
                .$estimatedValue.' in estimated sales, based on your typical spend per visit (estimate).',
            default => 'This charge covered '.$visits.' visits that counted.',
        };

        return new Content(view: 'emails.dunning-recovered', with: [
            'business' => $this->business,
            'bodyLine' => $bodyLine,
            'visitsCounted' => $visits,
            'estimatedValue' => $hasSpend ? $estimatedValue : null,
            'textAmount' => $textCents > 0 ? $textAmount : null,
        ]);
    }
}
