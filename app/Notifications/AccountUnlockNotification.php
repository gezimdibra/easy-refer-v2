<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-time unlock link for the per-account aggregate login lock
 * (spec Section 8.3, Amendment 5). Sent together with the login
 * attempt alert when the lock trips, so the legitimate owner always
 * has a way back in even where no Turnstile keys are configured.
 * The plaintext token exists only in this email; the application
 * stores only its hash (AccountUnlockService).
 */
class AccountUnlockNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // EU-S2: rendered on the custom shell (emails.notifications
        // .unlock); the lines stay on the message as the content
        // record and are passed to the view as its data, so the
        // copy lives in exactly one place.
        $lines = [
            'Sign-in to your account was temporarily locked because of repeated failed sign-in attempts.',
            'If this was you, use the button below to clear the lock. You will still need your password to sign in. The link works once and expires soon.',
            'If this was not you, ignore this email. Your password was not changed, and the lock stays in place to protect the account.',
        ];
        $url = route('account.unlock.show', ['token' => $this->token]);

        $mail = (new MailMessage)
            ->subject('Unlock your Easy Refer account')
            ->action('Unlock my account', $url)
            ->view('emails.notifications.unlock', ['lines' => $lines, 'url' => $url]);
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
