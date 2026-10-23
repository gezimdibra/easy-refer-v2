<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The newsletter double opt-in confirmation (spec Section 2.1 item
 * 8, inventory HOME-FTR-02): the footer form is a real endpoint, and
 * this queued mail carries the confirmation link. The link is built
 * from APP_URL like every emailed link (spec Section 11.1).
 */
class NewsletterConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $confirmUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your subscription');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.newsletter-confirmation');
    }
}
