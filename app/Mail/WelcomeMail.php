<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * The welcome email (saas-needs inventory, lifecycle item 1):
 * queued exactly once by RegisteredUserController on a successful
 * signup, and sent from nowhere else, so verification resends,
 * duplicate attempts, and seeded accounts can never re-send it.
 * It bridges signup to verification: the button repeats the
 * signed verification link (the framework's own construction),
 * so this message doubles as the recovery path when the
 * verification email lands in spam. Owners who verify before
 * the queued copy is delivered get the QR page as the action
 * instead; the state is read when the content is built.
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to Easy Refer: one step left before your code works');
    }

    public function content(): Content
    {
        $verified = $this->user->hasVerifiedEmail();

        return new Content(view: 'emails.welcome', with: [
            'business' => $this->user->business,
            'verified' => $verified,
            'actionUrl' => $verified ? route('owner.qr') : $this->verificationUrl(),
            'actionLabel' => $verified ? 'Get your QR code' : 'Verify your email address',
            'dashboardUrl' => route('dashboard'),
            'faqUrl' => route('faq'),
        ]);
    }

    /**
     * The signed verification link, built exactly the way the
     * framework's VerifyEmail notification builds it (spec
     * Section 11.1: emailed links derive from APP_URL).
     */
    private function verificationUrl(): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $this->user->getKey(),
                'hash' => sha1($this->user->getEmailForVerification()),
            ]
        );
    }
}
