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
        return (new MailMessage)
            ->subject('Unlock your Easy Refer account')
            ->line('Sign-in to your account was temporarily locked because of repeated failed attempts from multiple locations.')
            ->line('If this was you, use the button below to clear the lock. You will still need your password to sign in. The link works once and expires soon.')
            ->action('Unlock my account', route('account.unlock.show', ['token' => $this->token]))
            ->line('If this was not you, ignore this email. Your password was not changed, and the lock stays in place to protect the account.');
    }
}
