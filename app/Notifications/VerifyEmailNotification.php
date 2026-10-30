<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailBase;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * EU-S2 (R5 pack B0/B1): the verification email on the custom
 * shell instead of the vendor theme. The subject keeps the
 * framework's text verbatim; only the rendering changes (plus
 * the PROPOSED headline inside the branded view). The signed URL
 * is the base class's own construction, unchanged.
 */
class VerifyEmailNotification extends VerifyEmailBase
{
    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verify Email Address')
            ->view('emails.notifications.verify', ['url' => $url]);
    }
}
