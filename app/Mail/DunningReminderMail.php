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
 * Dunning day 3 (spec Section 9.6; inventory DUN-03 subject):
 * the reminder inside the grace window. The inventory carries
 * no separate day 3 body, so the body is DUN-02 again, the
 * same episode facts the day 0 email stated (the failed
 * record's amount, the grace end, the billing link); nothing
 * is invented for this send.
 */
class DunningReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public BillingRecord $record,
        public Carbon $graceEnd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reminder: update your card by '.$this->graceEnd->format('M j, Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dunning-reminder', with: [
            'business' => $this->business,
            'amount' => Money::usd($this->record->amount_cents),
            'graceEndDate' => $this->graceEnd->format('M j, Y'),
            'billingUrl' => route('settings.billing'),
        ]);
    }
}
