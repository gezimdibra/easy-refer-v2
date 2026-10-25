<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The pre-charge forecast email (RK-03; inventory BILL-FC-02):
 * sent by the daily forecast-reminders run once per charge
 * cycle, inside the 3 days before the charge, only when the
 * expected charge is nonzero. The body is composed by
 * App\Support\BillingForecast from the same settlement
 * preview the billing page and dashboard render, so the email
 * and the pages can never state different figures.
 */
class BillingForecastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your next Easy Refer charge');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.billing-forecast', with: [
            'business' => $this->business,
            'body' => $this->body,
            'billingUrl' => route('settings.billing'),
        ]);
    }
}
