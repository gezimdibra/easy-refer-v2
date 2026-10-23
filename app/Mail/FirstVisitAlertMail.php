<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The first-visit owner alert (spec Section 3 Step 11b, inventory
 * ALERT-01 and ALERT-02): exactly one per business, ever.
 */
class FirstVisitAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Business $business) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your first referred customer just visited');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.first-visit-alert', with: [
            'business' => $this->business,
            'dashboardUrl' => route('dashboard'),
        ]);
    }
}
