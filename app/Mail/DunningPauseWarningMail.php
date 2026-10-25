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
 * Dunning day 6 (spec Section 9.6; inventory DUN-04 subject,
 * DUN-02 + DUN-05 body): the final email inside the grace
 * window, stating the pause date plainly. The pause date is
 * the grace end: enrollment pauses when the grace window
 * closes, and redemptions of already-earned rewards are
 * never blocked (DUN-05's own words).
 */
class DunningPauseWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public BillingRecord $record,
        public Carbon $graceEnd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Easy Refer billing pauses on '.$this->graceEnd->format('M j, Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dunning-pause', with: [
            'business' => $this->business,
            'amount' => Money::usd($this->record->amount_cents),
            'graceEndDate' => $this->graceEnd->format('M j, Y'),
            'pauseDate' => $this->graceEnd->format('M j, Y'),
            'billingUrl' => route('settings.billing'),
        ]);
    }
}
