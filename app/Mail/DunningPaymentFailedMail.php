<?php

namespace App\Mail;

use App\Models\BillingRecord;
use App\Models\Business;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Dunning day 0 (spec Section 9.6; inventory DUN-01 subject,
 * DUN-02 body): sent by the dunning run once a webhook-driven
 * failure has put the business past_due. The amount is the
 * failed record's own amount; the grace end is the past_due
 * entry plus the specced grace window. The billing link is a
 * deep link into the authenticated billing page: it lands on
 * the login when signed out, authorizes nothing by itself,
 * and the Section 8.7 step-up still guards the card change
 * (PD-3).
 */
class DunningPaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public BillingRecord $record,
        public Carbon $graceEnd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Easy Refer payment did not go through');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dunning-failed', with: [
            'business' => $this->business,
            'amount' => Money::usd($this->record->amount_cents),
            'graceEndDate' => $this->graceEnd->format('M j, Y'),
            'billingUrl' => route('settings.billing'),
        ]);
    }
}
