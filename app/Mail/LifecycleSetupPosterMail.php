<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * D2, the poster-stall drip email (M-5 design pass Sections
 * 3.3 and 6.2; copy-inventory Amendment 72, [M5-D2-*]). Sent
 * by LifecycleMailService exactly once per business, only
 * while the flow is live and no poster event exists at send
 * time. The body asserts only the event's absence, never
 * where a poster is or is not (pass Section 4.6).
 *
 * Deliberately NOT ShouldQueue: renders at send time from
 * live facts (pass Section 4.1).
 */
class LifecycleSetupPosterMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Business $business) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your code is live. Your poster is the missing piece');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lifecycle-setup-poster', with: [
            'business' => $this->business,
            'mailTitle' => 'Your code is live. Your poster is the missing piece',
            'actionUrl' => route('owner.qr'),
        ]);
    }
}
