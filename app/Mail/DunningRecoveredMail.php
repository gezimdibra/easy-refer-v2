<?php

namespace App\Mail;

use App\Models\BillingRecord;
use App\Models\Business;
use App\Services\Billing\VisitValuation;
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
        $estimate = app(VisitValuation::class)->forMonth($this->business, $this->record->period_start);

        return new Content(view: 'emails.dunning-recovered', with: [
            'business' => $this->business,
            'visitsCounted' => $this->record->events()->where('coverage', 'billable')->count(),
            'estimatedValue' => Money::usd($estimate['estimated_sales_cents']),
        ]);
    }
}
